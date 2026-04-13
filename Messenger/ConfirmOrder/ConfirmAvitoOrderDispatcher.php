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

namespace BaksDev\Avito\Orders\Messenger\ConfirmOrder;


use BaksDev\Avito\Orders\Api\UpdateAvitoOrdersStatusRequest;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryDbsAvito;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryFbsAvito;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryPickupAvito;
use BaksDev\Avito\Type\Id\AvitoTokenUid;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\OrderMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Подтверждаем заказ Авито  */
#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final readonly class ConfirmAvitoOrderDispatcher
{
    public function __construct(
        #[Target('avitoOrdersLogger')] private LoggerInterface $Logger,
        private DeduplicatorInterface $deduplicator,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private UpdateAvitoOrdersStatusRequest $UpdateAvitoOrdersStatusRequest
    ) {}

    public function __invoke(OrderMessage $message): void
    {
        $Deduplicator = $this->deduplicator
            ->namespace('avito-orders')
            ->deduplication([$message->getId(), self::class]);

        if($Deduplicator->isExecuted())
        {
            return;
        }

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                'ozon-manufacture: не найдено активное событие заказа',
                [self::class.':'.__LINE__],
            );

            return;
        }

        /** Пропускаем, если тип заказа не Ozon FBS */
        if(
            false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsAvito::TYPE)
            && false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryDbsAvito::TYPE)
            && false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryPickupAvito::TYPE)
        )
        {
            $Deduplicator->save();
            return;
        }

        /** Пропускаем если заказ не является New «Новый» */
        if(false === $OrderEvent->isStatusEquals(OrderStatusNew::class))
        {
            $Deduplicator->save();
            return;
        }

        $AvitoTokenUid = new AvitoTokenUid($OrderEvent->getOrderTokenIdentifier());

        $isUpdate = $this->UpdateAvitoOrdersStatusRequest
            ->forTokenIdentifier($AvitoTokenUid)
            ->confirm()
            ->update($OrderEvent->getOrderNumber());

        if(false === $isUpdate)
        {
            $this->Logger->critical('avito-orders: Ошибка при обновлении статуса заказа');
            return;
        }

        $this->Logger->info(
            sprintf('%s: Подтвердили получение заказа', $OrderEvent->getOrderNumber()),
        );

        $Deduplicator->save();
    }
}
