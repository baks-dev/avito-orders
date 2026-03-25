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

namespace BaksDev\Avito\Orders\UseCase\Status\Cancel;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoDTO;
use BaksDev\Orders\Order\Repository\CurrentOrderNumber\CurrentOrderEventByNumberInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCanceled;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusCompleted;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusMarketplace;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusUnpaid;
use BaksDev\Orders\Order\UseCase\Admin\Status\OrderStatusHandler;

final readonly class CancelAvitoOrderStatusHandler
{
    public function __construct(
        private OrderStatusHandler $OrderStatusHandler,
        private CurrentOrderEventByNumberInterface $CurrentOrderEventByNumberRepository,
    ) {}

    public function handle(AvitoGetOrdersInfoDTO $command): array|false
    {
        $orders = [];

        $results = $this->CurrentOrderEventByNumberRepository->findAll($command->getOrderNumber());

        if(true === empty($results))
        {
            return false;
        }

        foreach($results as $OrderEvent)
        {
            if(
                true === $OrderEvent->isStatusEquals(OrderStatusCanceled::class)
                || true === $OrderEvent->isStatusEquals(OrderStatusMarketplace::class)
                || true === $OrderEvent->isDanger()
            )
            {
                continue;
            }


            /**
             * Делаем отмену заказа
             */

            $CancelAvitoOrderStatusDTO = new CancelAvitoOrderStatusDTO();
            $OrderEvent->getDto($CancelAvitoOrderStatusDTO);

            $CancelAvitoOrderStatusDTO
                ->orderDanger()
                ->setComment($command->getComment());


            /**
             * Если заказ New «Новый» либо Unpaid «В ожидании оплаты»
             * Автоматически отменяем «Новый» либо «Не оплаченный» заказ
             */
            if(
                true === $OrderEvent->isStatusEquals(OrderStatusNew::class)
                || true === $OrderEvent->isStatusEquals(OrderStatusUnpaid::class)
            )
            {
                $CancelAvitoOrderStatusDTO->cancelOrder();
            }


            /**
             * Если заказ Completed «Выполнен» - переносим его в статус Marketplace «Ожидается возврат службой
             * маркетплейса»
             */
            if(true === $OrderEvent->isStatusEquals(OrderStatusCompleted::class))
            {
                $CancelAvitoOrderStatusDTO->returnOrderMarketplace();
            }

            $orders[] = $this->OrderStatusHandler->handle($CancelAvitoOrderStatusDTO, false);
        }

        return $orders;
    }
}