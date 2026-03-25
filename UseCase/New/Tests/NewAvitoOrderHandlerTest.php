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

namespace BaksDev\Avito\Orders\UseCase\New\Tests;

use BaksDev\Avito\Orders\UseCase\New\NewAvitoOrderDTO;
use BaksDev\Avito\Orders\UseCase\New\NewAvitoOrderHandler;
use BaksDev\Avito\Orders\UseCase\New\Products\NewAvitoOrderProductDTO;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Type\Event\DeliveryEventUid;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\UseCase\Admin\Delete\Tests\DeleteOrderTest;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Products\Product\Repository\ProductDetail\ProductDetailByConstInterface;
use BaksDev\Products\Product\Repository\ProductDetail\ProductDetailByConstResult;
use BaksDev\Products\Product\Type\Event\ProductEventUid;
use BaksDev\Products\Product\Type\Id\ProductUid;
use BaksDev\Products\Product\Type\Offers\ConstId\ProductOfferConst;
use BaksDev\Products\Product\Type\Offers\Variation\ConstId\ProductVariationConst;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\ConstId\ProductModificationConst;
use BaksDev\Products\Product\UseCase\Admin\NewEdit\Tests\ProductsProductNewAdminUseCaseTest;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\Tests\NewUserProfileHandlerTest;
use BaksDev\Users\User\Type\Id\UserUid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use BaksDev\Products\Product\UseCase\Admin\Invariable\Tests\ProductInvariableAdminUseCaseTest;

#[When(env: 'test')]
#[Group('avito-orders')]
#[Group('avito-orders-usecase')]
final class NewAvitoOrderHandlerTest extends KernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        // Бросаем событие консольной комманды
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $event = new ConsoleCommandEvent(new Command(), new StringInput(''), new NullOutput());
        $dispatcher->dispatch($event, 'console.command');

        NewUserProfileHandlerTest::tearDownAfterClass();
        DeleteOrderTest::tearDownAfterClass();


        /** Создаем тестовый продукт */
        ProductsProductNewAdminUseCaseTest::setUpBeforeClass();
        new ProductsProductNewAdminUseCaseTest('')->testUseCase();
        ProductInvariableAdminUseCaseTest::setUpBeforeClass();
        new ProductInvariableAdminUseCaseTest('')->testUseCase();
    }

    public function testUseCase(): void
    {
        $NewAvitoOrderHandler = self::getContainer()->get(NewAvitoOrderHandler::class);

        $newAvitoOrderDTO = new NewAvitoOrderDTO();

        $newAvitoOrderDTO
            ->getInvariable()
            ->setNumber('A-1234567')
            ->setUsr(new UserUid())
            ->setProfile(new UserProfileUid());

        $newAvitoOrderDTO->setCreated(new DateTimeImmutable());

        $newAvitoOrderDTO
            ->getUsr()
            ->getPayment()
            ->setPayment(new PaymentUid());

        $newAvitoOrderDTO
            ->getUsr()
            ->getDelivery()
            ->setDelivery(new DeliveryUid())
            ->setAddress('test')
            ->setEvent(new DeliveryEventUid())
            ->setLatitude(new GpsLatitude())
            ->setLongitude(new GpsLongitude());


        $ProductDetailByConstRepository = self::getContainer()->get(ProductDetailByConstInterface::class);


        /**
         * Находим идентификаторы тестового продукта
         * @var ProductDetailByConstInterface $ProductDetailByConstRepository
         */
        $ProductDetailByConstResult = $ProductDetailByConstRepository
            ->product(new ProductUid(ProductUid::TEST))
            ->offerConst(new ProductOfferConst(ProductOfferConst::TEST))
            ->variationConst(new ProductVariationConst(ProductVariationConst::TEST))
            ->modificationConst(new ProductModificationConst(ProductModificationConst::TEST))
            ->findResult();

        if(false === ($ProductDetailByConstResult instanceof ProductDetailByConstResult))
        {
            return;
        }

        $newAvitoOrderDTO
            ->addProduct(new NewAvitoOrderProductDTO()
                ->setArticle('test')
                ->setProduct(new ProductEventUid())
                ->setOffer($ProductDetailByConstResult->getProductOfferUid())
                ->setVariation($ProductDetailByConstResult->getProductVariationUid())
                ->setModification($ProductDetailByConstResult->getProductModificationUid())
            );

        $newAvitoOrderDTO
            ->getUsr()
            ->setProfile(new UserProfileEventUid());

        $newAvitoOrderDTO
            ->getPosting()
            ->setValue('test');


        /** @var NewAvitoOrderHandler $handle */
        $handle = $NewAvitoOrderHandler->handle($newAvitoOrderDTO);

        self::assertInstanceOf(Order::class, $handle);
    }
}