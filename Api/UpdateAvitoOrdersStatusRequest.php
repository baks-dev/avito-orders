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

namespace BaksDev\Avito\Orders\Api;

use BaksDev\Avito\Api\AvitoApi;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use const BaksDev\Reference\Car\Type\CarModels\Id\Models\Collection\string;

#[Autoconfigure(shared: false)]
final class UpdateAvitoOrdersStatusRequest extends AvitoApi
{
    private ?string $status = null;

    public function confirm(): self
    {
        $this->status = 'confirm';
        return $this;
    }

    public function extradition(): self
    {
        $this->status = 'perform';
        return $this;
    }

    public function completed(): self
    {
        $this->status = 'receive';
        return $this;
    }

    public function cancel(): self
    {
        $this->status = 'reject';
        return $this;
    }

    /**
     * Изменение статуса заказа
     *
     * confirm - подтверждение заказа;
     * reject - отмена заказа;
     * perform - подтверждение отправки заказа (RDBS);
     * receive - подтверждение доставки заказа (RDBS, CNC).
     *
     * @see https://developers.avito.ru/api-catalog/order-management/documentation#operation/applyTransition
     */
    public function update(int|string $order): bool
    {
        if(false === $this->isExecuteEnvironment())
        {
            return true;
        }

        if(false === empty($this->status))
        {
            throw new InvalidArgumentException('Invalid Argument Status');
        }

        /** Собираем в массив и присваиваем в переменную тело запроса */
        $body = [
            'orderId' => (string) $order,
            'transition' => $this->status,
        ];

        $response = $this
            ->TokenHttpClient()
            ->request(
                'POST',
                '/order-management/1/order/applyTransition',
                ["json" => $body],
            );

        if($response->getStatusCode() !== 200)
        {
            $content = $response->toArray(false);

            $this->logger->critical(
                'avito-orders: Ошибка при обновлении статуса заказа',
                [
                    self::class.':'.__LINE__,
                    $body,
                    $content,
                    $this->getUser(),
                    $this->getTokenIdentifier(),
                ],
            );

            return false;
        }

        return true;
    }
}