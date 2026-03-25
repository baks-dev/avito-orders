<?php
/*
 * Copyright 2026.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Avito\Orders\Messenger\Schedules\CancelOrders;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoDTO;
use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoRequest;
use BaksDev\Avito\Orders\Schedule\CancelOrders\CancelOrdersSchedule;
use BaksDev\Avito\Orders\UseCase\Status\Cancel\CancelAvitoOrderStatusHandler;
use BaksDev\Avito\Repository\AllTokensByProfile\AvitoTokensByProfileInterface;
use BaksDev\Centrifugo\Server\Publish\CentrifugoPublishInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final readonly class CancelAvitoOrderScheduleHandler
{
    public function __construct(
        #[Target('avitoOrdersLogger')] private LoggerInterface $Logger,
        private AvitoGetOrdersInfoRequest $AvitoGetOrdersInfoRequest,
        private CancelAvitoOrderStatusHandler $CancelAvitoOrderStatusHandler,
        private AvitoTokensByProfileInterface $AvitoTokensByProfileRepository,
        private DeduplicatorInterface $Deduplicator,
        private CentrifugoPublishInterface $publish,
    ) {}

    public function __invoke(CancelAvitoOrdersScheduleMessage $message): void
    {
        /** Получаем все токены профиля */

        $tokensByProfile = $this->AvitoTokensByProfileRepository
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }

        foreach($tokensByProfile as $AvitoTokenUid)
        {
            /**
             * Ограничиваем периодичность запросов для одного токена
             */

            $Deduplicator = $this->Deduplicator
                ->namespace('avito-orders')
                ->expiresAfter(CancelOrdersSchedule::INTERVAL)
                ->deduplication([self::class, (string) $AvitoTokenUid]);

            if($Deduplicator->isExecuted())
            {
                return;
            }

            $Deduplicator->save();


            /**
             * Получаем список ОТМЕНЕННЫХ сборочных заданий по основному идентификатору компании
             */

            $orders = $this->AvitoGetOrdersInfoRequest
                ->forTokenIdentifier($AvitoTokenUid)
                ->findAll();

            if(false === $orders || false === $orders->valid())
            {
                $Deduplicator->delete();
                continue;
            }

            $this->ordersCancel($orders, $message->getProfile());
            $Deduplicator->delete();
        }
    }

    private function ordersCancel(Generator $orders, UserProfileUid $profile): void
    {
        /** Индекс дедубдикации по номеру заказа */
        $Deduplicator = $this->Deduplicator
            ->namespace('avito-orders')
            ->expiresAfter('1 day');


        /** @var AvitoGetOrdersInfoDTO $AvitoGetOrdersInfoDTO */
        foreach($orders as $AvitoGetOrdersInfoDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator
                ->deduplication([
                    $AvitoGetOrdersInfoDTO->getOrderNumber(),
                    self::class,
                ]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            $Deduplicator->save();

            $arrOrdersCancel = $this->CancelAvitoOrderStatusHandler->handle($AvitoGetOrdersInfoDTO);


            /**
             * Если заказов для отмены не найдено
             */

            if(false === is_array($arrOrdersCancel))
            {
                $this->Logger->critical(
                    sprintf(
                        'avito-orders: Ошибка при отмене заказа %s',
                        $AvitoGetOrdersInfoDTO->getOrderNumber()
                    ),
                    [
                        self::class.':'.__LINE__,
                        'attr' => (string) $profile->getAttr(),
                        'profile' => (string) $profile,
                    ],
                );

                continue;
            }


            /**
             * Если имеются заказы для отмены - скрываем их идентификатор
             */

            $this->Logger->info(
                sprintf('Отменили заказ %s', $AvitoGetOrdersInfoDTO->getOrderNumber()),
                [
                    self::class.':'.__LINE__,
                    'attr' => (string) $profile->getAttr(),
                    'profile' => (string) $profile,
                ],
            );

            foreach($arrOrdersCancel as $Order)
            {
                /**
                 * Скрываем идентификатор у всех пользователей
                 */

                $this->publish
                    ->addData(['profile' => false]) // Скрывает у всех
                    ->addData(['identifier' => (string) $Order->getId()])
                    ->send('remove');

                $this->publish
                    ->addData(['profile' => false]) // Скрывает у всех
                    ->addData(['order' => (string) $Order->getId()])
                    ->send('orders');
            }
        }
    }
}
