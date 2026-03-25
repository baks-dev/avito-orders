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

namespace BaksDev\Avito\Orders\Api\Get\OrdersInfo;

use BaksDev\Avito\Api\AvitoApi;
use DateInterval;
use DateTimeImmutable;
use Generator;
use JsonException;

final class AvitoGetOrdersInfoRequest extends AvitoApi
{
    private int $page = 1;

    private ?DateTimeImmutable $fromDate = null;

    private DateInterval $interval;

    private ?string $status = null;

    public function interval(DateInterval|string|null $interval): self
    {
        if(empty($interval))
        {
            $this->interval = DateInterval::createFromDateString('1 hour');
            return $this;
        }

        if($interval instanceof DateInterval)
        {
            $this->interval = $interval;

            return $this;
        }

        $this->interval = DateInterval::createFromDateString($interval);

        return $this;
    }


    /** Вызываем метод, если необходимо найти только НОВЫЕ заказы */
    public function getNew(): self
    {
        $this->status = 'on_confirmation';
        return $this;
    }


    /** Вызываем метод, если необходимо найти только отменённые заказы */
    public function getCanceled(): self
    {
        $this->status = 'canceled';
        return $this;
    }


    /**
     * Получение информации по заказам
     *
     * dateFrom int - Метка времени, с момента которого созданы покупки
     * page int - Номер страницы для пагинации
     * limit int [ 0 .. 20 ] - Максимальное количество заказов на странице
     * statuses array [strings (status): "on_confirmation" "ready_to_ship" "in_transit" "canceled" "delivered"
     * "on_return" "in_dispute" "closed"] - Статус, по которому нужно получить заказы.
     *
     * Статусы:
     * on_confirmation - ожидает подтверждения
     * ready_to_ship - ждет отправки
     * in_transit - в пути
     * canceled - отменный заказ
     * delivered - доставлен покупателю
     * on_return - на возврате
     * in_dispute - по заказу открыт спор
     * closed - заказ закрыт
     *
     * @see https://developers.avito.ru/api-catalog/order-management/documentation#operation/getOrders
     * @return Generator<int, AvitoGetOrdersInfoDTO>|false
     */
    public function findAll(): Generator|false
    {
        $dateTimeNow = new DateTimeImmutable();

        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            // Новые заказы за последние 30 минут (планировщик на каждую минуту)
            $this->fromDate = $dateTimeNow
                ->sub($this->interval ?? DateInterval::createFromDateString('30 hour'));
        }

        while(true)
        {
            /** Собираем массив и присваиваем в переменную query параметры запроса */
            $query = [
                'dateFrom' => $this->fromDate->getTimestamp(),
                'page' => $this->page,
                'limit' => 20,
            ];

            if(false === empty($this->status))
            {
                $query['statuses'] = [$this->status];
            }

            $response = $this
                ->TokenHttpClient()
                ->request(
                    'GET',
                    '/order-management/1/orders',
                    ['query' => $query],
                );

            try
            {
                $content = $response->toArray(false);
            }
            catch(JsonException)
            {
                return false;
            }


            if($response->getStatusCode() !== 200)
            {
                $this->logger->critical(
                    'avito-orders: Ошибка получения заказов',
                    [
                        self::class.':'.__LINE__,
                        $content,
                    ]);

                return false;
            }

            if(false === empty($content['orders']))
            {
                foreach($content['orders'] as $order)
                {
                    yield new AvitoGetOrdersInfoDTO($order);
                }
            }

            if(false === $content['hasMore'])
            {
                break;
            }
        }
    }
}