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

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\Product\AvitoGetOrdersInfoProductDTO;
use BaksDev\Reference\Money\Type\Money;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;

final class AvitoGetOrdersInfoDTO
{
    private string $id;

    private DateTimeImmutable $creationDate;

    private ArrayCollection $products;

    private ?string $address = null;

    private ?string $number = null;

    private ?string $posting = null;

    private ?string $buyerName = null;

    private ?string $buyerNumber = null;

    private DateTimeImmutable $deliveryDate;

    private ?string $type = null;

    private ?string $comment = null;

    private ?string $status = null;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->creationDate = new DateTimeImmutable($data['createdAt']);


        /**
         * on_confirmation - ожидает подтверждения
         * ready_to_ship - ждет отправки
         * in_transit - в пути
         * canceled - отменный заказ
         * delivered - доставлен покупателю
         * on_return - на возврате
         * in_dispute - по заказу открыт спор
         * closed - заказ закрыт
         * */
        $this->status = $data['status'];


        /**
         * Products
         */
        $this->products = new ArrayCollection();

        foreach($data['items'] as $item)
        {
            /**
             * Проверяем, является ли продукт комплектом из нескольких - в таком случае цену одного товара необходимо
             * разделить на количество в комплекте, а количество умножить на это число
             */
            $kit = 1;
            if(preg_match('/KIT(\d+)$/', $item['id'], $matches))
            {
                $kit = $matches[1];
            }

            $this->products->add(new AvitoGetOrdersInfoProductDTO(
                preg_replace('/-KIT\d+$/', '', $item['id']),
                new Money($item['prices']['price'] / $kit),
                $item['count'] * $kit,
            ));
        }

        /** Постинг - это номер отслеживания, кроме случаев, когда его нет - тогда это идентификатор заказа */
        $this->number = $data['marketplaceId'];
        $this->posting = $data['delivery']['trackingNumber'] ?? $data['id'];


        /**
         * Покупатель
         */
        $this->buyerName = isset($data['delivery']['buyerInfo']) ? $data['delivery']['buyerInfo']['fullName'] : null;
        $this->buyerNumber = isset($data['delivery']['buyerInfo']) ? $data['delivery']['buyerInfo']['phoneNumber'] : null;


        /**
         * Дата доставки
         */
        $this->deliveryDate = new DateTimeImmutable($data['schedules']['deliveryDateMin']);


        // Способ доставки
        // cnc - Самовывоз от продавца
        $this->type = $data['delivery']['serviceType'] ?? null;


        /**
         * Адрес доставки
         */

        $this->address = isset($data['delivery']['terminalInfo'])
            ? $data['delivery']['terminalInfo']['address']
            : current($data['items'])['location'];

        if(isset($data['delivery']['courierInfo']))
        {
            $this->address = $data['delivery']['courierInfo']['address'];
        }

        if(isset($data['delivery']['terminalInfo']))
        {
            $this->address = $data['delivery']['terminalInfo']['address'];
        }

        $this->comment = $data['delivery']['serviceName'] ?? null;

    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreationDate(): DateTimeImmutable
    {
        return new $this->creationDate;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    /** @return ArrayCollection<AvitoGetOrdersInfoProductDTO> */
    public function getProducts(): ArrayCollection
    {
        return $this->products;
    }

    public function getPostingNumber(): ?string
    {
        return 'A-'.$this->posting;
    }

    public function getOrderNumber(): ?string
    {
        return 'A-'.$this->number;
    }

    public function getDeliveryDate(): DateTimeImmutable
    {
        return $this->deliveryDate;
    }

    public function getBuyerName(): ?string
    {
        return $this->buyerName;
    }

    public function getBuyerNumber(): ?string
    {
        return $this->buyerNumber;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }
}