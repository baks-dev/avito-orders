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

namespace BaksDev\Avito\Orders\UseCase\Status\Cancel\Tests;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoDTO;
use BaksDev\Avito\Orders\UseCase\New\Tests\NewAvitoOrderHandlerTest;
use BaksDev\Avito\Orders\UseCase\Status\Cancel\CancelAvitoOrderStatusHandler;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Products\Product\UseCase\Admin\Invariable\Tests\ProductInvariableAdminUseCaseTest;
use BaksDev\Products\Product\UseCase\Admin\NewEdit\Tests\ProductsProductNewAdminUseCaseTest;
use BaksDev\Users\Profile\UserProfile\UseCase\Admin\NewEdit\Tests\NewUserProfileHandlerTest;
use PHPUnit\Framework\Attributes\DependsOnClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'test')]
#[Group('avito-orders')]
#[Group('avito-orders-usecase')]
class CancelAvitoOrderStatusHandlerTest extends KernelTestCase
{
    #[DependsOnClass(NewAvitoOrderHandlerTest::class)]
    public function testUseCase(): void
    {
        $CurrentOrderEventRepository = self::getContainer()->get(CurrentOrderEventInterface::class);


        /** @var CurrentOrderEventInterface $CurrentOrderEventRepository */
        $OrderEvent = $CurrentOrderEventRepository
            ->forOrder(OrderUid::TEST)
            ->find();

        if($OrderEvent === false)
        {
            return;
        }

        $AvitoGetOrdersInfoDTO = new AvitoGetOrdersInfoDTO([
            'id' => 'test',
            'createdAt' => 'now',
            'status' => 'canceled',
            'marketplaceId' => '1234567',
            'delivery' => ['serviceName' => 'test', 'terminalInfo' => ['address' => 'test']],
            'schedules' => ['deliveryDateMin' => 'now'],
            'items' => [],
        ]);


        /** @var CancelAvitoOrderStatusHandler $CancelAvitoOrderStatusHandler */
        $CancelAvitoOrderStatusHandler = self::getContainer()->get(CancelAvitoOrderStatusHandler::class);
        $handle = $CancelAvitoOrderStatusHandler->handle($AvitoGetOrdersInfoDTO);

        self::assertTrue(is_array($handle), 'Ошибка AvitoOrder');
    }

    public static function tearDownAfterClass(): void
    {
        /** Удаляем тестовый заказ */
        OrderNewTest::setUpBeforeClass();


        /** Удаляем тестовый профиль */
        NewUserProfileHandlerTest::setUpBeforeClass();


        /** Удаляем тестовый продукт */
        ProductsProductNewAdminUseCaseTest::setUpBeforeClass();
        ProductInvariableAdminUseCaseTest::setUpBeforeClass();
    }
}
