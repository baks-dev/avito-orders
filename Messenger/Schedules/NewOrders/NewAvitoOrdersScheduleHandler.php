<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Avito\Orders\Messenger\Schedules\NewOrders;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoDTO;
use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoRequest;
use BaksDev\Avito\Orders\Api\Get\OrdersInfo\Product\AvitoGetOrdersInfoProductDTO;
use BaksDev\Avito\Orders\Schedule\NewOrders\NewOrdersSchedule;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryDbsAvito;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryFbsAvito;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryPickupAvito;
use BaksDev\Avito\Orders\Type\PaymentType\TypePaymentDbsAvito;
use BaksDev\Avito\Orders\Type\PaymentType\TypePaymentFbsAvito;
use BaksDev\Avito\Orders\Type\PaymentType\TypePaymentPickupAvito;
use BaksDev\Avito\Orders\Type\ProfileType\TypeProfileDbsAvito;
use BaksDev\Avito\Orders\Type\ProfileType\TypeProfileFbsAvito;
use BaksDev\Avito\Orders\Type\ProfileType\TypeProfilePickupAvito;
use BaksDev\Avito\Orders\UseCase\New\NewAvitoOrderDTO;
use BaksDev\Avito\Orders\UseCase\New\NewAvitoOrderHandler;
use BaksDev\Avito\Orders\UseCase\New\Products\Items\NewAvitoOrderProductItemDTO;
use BaksDev\Avito\Orders\UseCase\New\Products\NewAvitoOrderProductDTO;
use BaksDev\Avito\Orders\UseCase\New\User\Delivery\Field\NewAvitoOrderDeliveryFieldDTO;
use BaksDev\Avito\Orders\UseCase\New\User\UserProfile\Value\NewAvitoUserProfileValueDTO;
use BaksDev\Avito\Repository\AllTokensByProfile\AvitoTokensByProfileInterface;
use BaksDev\Avito\Type\Id\AvitoTokenUid;
use BaksDev\Contacts\Region\Repository\PickupByGeolocation\PickupByGeolocationInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Delivery\Type\Event\DeliveryEventUid;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\Field\Pack\Phone\Type\PhoneField;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\ExistsOrderNumber\ExistsOrderNumberInterface;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Reference\Currency\Type\Currencies\RUR;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Address\Api\YandexMarketAddressRequest;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileById\UserProfileByIdInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileById\UserProfileResult;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Entity\User;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final readonly class NewAvitoOrdersScheduleHandler
{
    public function __construct(
        #[Target('avitoOrdersLogger')] private LoggerInterface $Logger,
        private AvitoGetOrdersInfoRequest $AvitoGetOrdersInfoRequest,
        private NewAvitoOrderHandler $AvitoOrderHandler,
        private AvitoTokensByProfileInterface $AvitoTokensByProfile,
        private DeduplicatorInterface $Deduplicator,
        private YandexMarketAddressRequest $YandexMarketAddressRequest,
        private UserProfileByIdInterface $UserProfileByIdRepository,
        private UserByUserProfileInterface $UserByUserProfileRepository,
        private ProductConstByArticleInterface $ProductConstByArticleRepository,
        private FieldValueFormInterface $FieldValueRepository,
        private FieldByDeliveryChoiceInterface $deliveryFields,
        private CurrentDeliveryEventInterface $currentDeliveryEvent,
        private ExistsOrderNumberInterface $ExistsOrderNumberRepository,
        private PickupByGeolocationInterface $pickupByGeolocation,
    ) {}

    public function __invoke(NewAvitoOrdersScheduleMessage $message): void
    {
        /** Получаем все токены профиля */

        $tokensByProfile = $this->AvitoTokensByProfile
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }


        foreach($tokensByProfile as $avitoTokenUid)
        {
            /**
             * Ограничиваем периодичность запросов
             */

            $Deduplicator = $this->Deduplicator
                ->namespace('avito-orders')
                ->expiresAfter($message->getInterval() ?: NewOrdersSchedule::INTERVAL)
                ->deduplication([self::class, (string) $avitoTokenUid]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            /** Добавляем дедубликатор обновления (удалям в конце данного процесса) */
            $Deduplicator->save();

            /**
             * Получаем список НОВЫХ сборочных заданий по основному идентификатору компании
             */

            $orders = $this->AvitoGetOrdersInfoRequest
                ->forTokenIdentifier($avitoTokenUid)
                ->interval($message->getInterval() ?: NewOrdersSchedule::INTERVAL)
                ->getNew()
                ->findAll();

            if(false === $orders || false === $orders->valid())
            {
                $Deduplicator->delete();
                continue;
            }

            $this->ordersCreate($orders, $avitoTokenUid, $message->getProfile());

            /** Удаляем дедубликатор обновления */
            $Deduplicator->delete();
        }
    }

    /** @param Generator<NewAvitoOrderDTO> $orders */
    private function ordersCreate(Generator $orders, AvitoTokenUid $token, UserProfileUid $profile): void
    {
        /** @var AvitoGetOrdersInfoDTO $avitoGetOrdersInfoDTO */
        foreach($orders as $avitoGetOrdersInfoDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->Deduplicator
                ->namespace('avito-orders')
                ->deduplication([
                    $avitoGetOrdersInfoDTO->getPostingNumber(),
                    self::class,
                ]);


            if($Deduplicator->isExecuted())
            {
                continue;
            }

            if($avitoGetOrdersInfoDTO->getStatus() !== 'on_confirmation')
            {
                $Deduplicator->save();
                continue;
            }


            /**
             * Пропускаем, если заказ уже существует в системе
             */

            $isExists = $this->ExistsOrderNumberRepository->isExists($avitoGetOrdersInfoDTO->getPostingNumber());

            if($isExists)
            {
                $Deduplicator->save();
                continue;
            }

            $User = $this->UserByUserProfileRepository
                ->forProfile($profile)
                ->find();

            if(false === ($User instanceof User))
            {
                $this->Logger->critical(
                    'avito-orders: Пользователь по профилю не найден',
                    [self::class.':'.__LINE__, (string) $profile],
                );

                continue;
            }

            /** Создаем и заполняем DTO нового заказа */
            $avitoOrderDTO = new NewAvitoOrderDTO();

            /**
             * Invariable
             */

            $avitoOrderDTO->setCreated($avitoGetOrdersInfoDTO->getCreationDate());

            $avitoOrderDTO
                ->getInvariable()
                ->setCreated($avitoGetOrdersInfoDTO->getCreationDate() ?: new DateTimeImmutable('now'))
                ->setProfile($profile)
                ->setToken($token)
                ->setNumber($avitoGetOrdersInfoDTO->getOrderNumber())
                ->setUsr($User);


            /** Posting */
            $avitoOrderDTO
                ->getPosting()
                ->setValue($avitoGetOrdersInfoDTO->getPostingNumber());


            /**
             * Продукция
             *
             * @var AvitoGetOrdersInfoProductDTO $product
             */
            foreach($avitoGetOrdersInfoDTO->getProducts() as $product)
            {
                $newOrderProductDTO = new NewAvitoOrderProductDTO()->setArticle($product->getArticle());

                $newOrderPriceDTO = $newOrderProductDTO->getPrice();

                $productData = $this->ProductConstByArticleRepository->find($product->getArticle());

                if(false === ($productData instanceof CurrentProductByBarcodeResult))
                {
                    /** Если какой-то продукт для данного заказа не был найден - пропускаем такой заказ */
                    $this->Logger->warning(
                        sprintf('Артикул товара %s не найден', $product->getArticle()),
                        [self::class.':'.__LINE__],
                    );
                    continue 2;
                }

                $newOrderPriceDTO
                    ->setPrice(new Money($product->getTotalPrice())) // Стоимость товара в валюте магазина до применения скидок.
                    ->setCurrency(new Currency(RUR::CURRENCY))
                    ->setTotal($product->getCount());

                $avitoOrderDTO->addProduct($newOrderProductDTO
                    ->setProduct($productData->getEvent())
                    ->setOffer($productData->getOffer())
                    ->setVariation($productData->getVariation())
                    ->setModification($productData->getModification()),
                );


                /**
                 * Items
                 * Создаем единицу продукта по количеству продукта в заказе
                 */
                for($i = 0; $i < $product->getCount(); $i++)
                {
                    $item = new NewAvitoOrderProductItemDTO();

                    /**
                     * Присваиваем цену из продукта в заказе
                     */
                    $item->getPrice()
                        ->setPrice($product->getTotalPrice())
                        ->setCurrency(new Currency(RUR::CURRENCY));

                    $newOrderProductDTO->addItem($item);
                }
            }


            $OrderProfileDTO = $avitoOrderDTO->getUsr()->getUserProfile();

            /**
             * Тип профиля пользователя
             */

            $profile_type = match ($avitoGetOrdersInfoDTO->getType())
            {
                'pvz' => TypeProfileFbsAvito::class,
                'dbs', 'rdbs' => TypeProfileDbsAvito::class,
                default => TypeProfilePickupAvito::class,
            };

            $Profile = new TypeProfileUid($profile_type);
            $OrderProfileDTO->setType($Profile);


            /**
             * Способ оплаты
             */

            $OrderPaymentDTO = $avitoOrderDTO->getUsr()->getPayment();

            $payment_type = match ($avitoGetOrdersInfoDTO->getType())
            {
                'pvz' => TypePaymentFbsAvito::class,
                'dbs', 'rdbs' => TypePaymentDbsAvito::class,
                default => TypePaymentPickupAvito::class,
            };

            $payment = new PaymentUid($payment_type);
            $OrderPaymentDTO->setPayment($payment);


            /**
             * Способ доставки Avito
             */

            $orderDeliveryDTO = $avitoOrderDTO->getUsr()->getDelivery();

            $delivery_type = match ($avitoGetOrdersInfoDTO->getType())
            {
                'pvz' => TypeDeliveryFbsAvito::class,
                'dbs', 'rdbs' => TypeDeliveryDbsAvito::class,
                default => TypeDeliveryPickupAvito::class,
            };

            $delivery = new DeliveryUid($delivery_type);
            $address = $avitoGetOrdersInfoDTO->getAddress();

            $orderDeliveryDTO
                ->setDelivery($delivery)
                ->setDeliveryDate($avitoGetOrdersInfoDTO->getDeliveryDate());


            /**
             * Получим данные для заполнения координат по адресу - если адрес был возвращен по API
             * - находим по нему координаты;
             */
            if(false === empty($address))
            {
                $avitoAddressResult = $this->YandexMarketAddressRequest->getAddress($address);

                if(true === empty($avitoAddressResult))
                {
                    continue;
                }

                $latitude = $avitoAddressResult->getLatitude();
                $longitude = $avitoAddressResult->getLongitude();
                $address = $avitoAddressResult->getAddress();

                $orderDeliveryDTO
                    ->setAddress($address)
                    ->setLatitude($latitude)
                    ->setLongitude($longitude);
            }


            /** Если адрес не указан, либо Самовывоз - присваиваем адрес профиля */
            if(true === empty($address) || $delivery->equals(TypeDeliveryPickupAvito::class))
            {
                $userProfileByIdResult = $this->UserProfileByIdRepository
                    ->profile($profile)
                    ->find();

                if(false === ($userProfileByIdResult instanceof UserProfileResult))
                {
                    continue;
                }

                $address = $userProfileByIdResult->getLocation();
                $latitude = $userProfileByIdResult->getLatitude();
                $longitude = $userProfileByIdResult->getLongitude();

                $orderDeliveryDTO
                    ->setAddress($address)
                    ->setLatitude($latitude)
                    ->setLongitude($longitude);
            }

            $avitoOrderDTO->setComment($avitoGetOrdersInfoDTO->getComment());

            /** Присваиваем информацию о покупателе */
            $buyer = [];

            if(false === empty($avitoGetOrdersInfoDTO->getBuyerName()))
            {
                $buyer['name'] = $avitoGetOrdersInfoDTO->getBuyerName();
            }

            if(false === empty($avitoGetOrdersInfoDTO->getBuyerNumber()))
            {
                $buyer['number'] = $avitoGetOrdersInfoDTO->getBuyerNumber();
            }

            $avitoOrderDTO->setBuyer($buyer);

            $this->fillProfile($avitoOrderDTO);
            $this->fillDelivery($avitoOrderDTO, $avitoGetOrdersInfoDTO);


            $handle = $this->AvitoOrderHandler->handle($avitoOrderDTO);

            if($handle instanceof Order)
            {
                $this->Logger->info(
                    sprintf('Добавили новый заказ %s', $avitoOrderDTO->getPostingNumber()),
                    [self::class.':'.__LINE__],
                );

                $Deduplicator->save();
            }
        }
    }

    private function fillProfile(NewAvitoOrderDTO $command): void
    {
        if(empty($command->getBuyer()))
        {
            return;
        }

        /** Профиль пользователя  */
        $userProfileDTO = $command->getUsr()->getUserProfile();

        if(null === $userProfileDTO)
        {
            return;
        }

        /** Идентификатор типа профиля  */
        $typeProfileUid = $userProfileDTO->getType();

        if(null === $typeProfileUid)
        {
            return;
        }


        /** Определяем свойства клиента при доставке DBS */
        $profileFields = $this->FieldValueRepository->get($typeProfileUid);

        /** @var FieldValueFormDTO $profileField */
        foreach($profileFields as $profileField)
        {
            if($profileField->getType()->getType() === 'contact_field')
            {
                $userProfileValueDTO = new NewAvitoUserProfileValueDTO();
                $userProfileValueDTO->setField($profileField->getField());
                $userProfileValueDTO->setValue($command->getBuyer()['name']);
                $userProfileDTO->addValue($userProfileValueDTO);

                continue;
            }

            if(isset($command->getBuyer()['phone']) && $profileField->getType()->getType() === 'phone_field')
            {
                $phone = PhoneField::formater($command->getBuyer()['phone']);

                $userProfileValueDTO = new NewAvitoUserProfileValueDTO();
                $userProfileValueDTO->setField($profileField->getField());
                $userProfileValueDTO->setValue($phone);
                $userProfileDTO->addValue($userProfileValueDTO);
            }

        }
    }

    private function fillDelivery(NewAvitoOrderDTO $command, AvitoGetOrdersInfoDTO $avitoGetOrdersInfoDTO): void
    {
        $orderDeliveryDTO = $command->getUsr()->getDelivery();


        /**
         * Определяем свойства доставки и присваиваем адрес
         */

        $fields = $this->deliveryFields->fetchDeliveryFields($orderDeliveryDTO->getDelivery());


        /** Указываем адрес доставки */

        if($fields)
        {
            $addressField = array_filter($fields, static function($v) {
                /** @var InputField $InputField */
                return $v->getType()->getType() === 'address_field';
            });

            $addressField = current($addressField);

            if($addressField)
            {
                $orderDeliveryFieldDTO = new NewAvitoOrderDeliveryFieldDTO();
                $orderDeliveryFieldDTO->setField($addressField);
                $orderDeliveryFieldDTO->setValue($orderDeliveryDTO->getAddress());
                $orderDeliveryDTO->addField($orderDeliveryFieldDTO);
            }

            /** При самовывозе указываем ПВЗ */
            if($avitoGetOrdersInfoDTO->getType() === 'cnc')
            {
                $contactsRegion = array_filter($fields, static function($v) {
                    /** @var InputField $InputField */
                    return $v->getType()->getType() === 'contacts_region_type';
                });

                $contactsField = current($contactsRegion);

                if($contactsField)
                {
                    $orderDeliveryFieldDTO = new NewAvitoOrderDeliveryFieldDTO();
                    $orderDeliveryFieldDTO->setField($contactsField);

                    /** Определяем по геолокации ПВЗ */
                    $pickupByGeolocationDTO = $this->pickupByGeolocation
                        ->latitude($orderDeliveryDTO->getLatitude())
                        ->longitude($orderDeliveryDTO->getLongitude())
                        ->execute();

                    if($pickupByGeolocationDTO)
                    {
                        $orderDeliveryFieldDTO->setValue((string) $pickupByGeolocationDTO->getId());
                    }

                    $orderDeliveryDTO->addField($orderDeliveryFieldDTO);
                }
            }
        }


        /**
         * Присваиваем активное событие доставки
         */

        $deliveryEventUid = $this->currentDeliveryEvent
            ->forDelivery($orderDeliveryDTO->getDelivery())
            ->getId();

        if(false === $deliveryEventUid instanceof DeliveryEventUid)
        {
            throw new InvalidArgumentException(
                sprintf(
                    'Способ доставки не найден! Выполните комманду Upgrade типа %s : ',
                    $orderDeliveryDTO->getDelivery()
                ),
            );
        }

        $orderDeliveryDTO->setEvent($deliveryEventUid);
    }
}
