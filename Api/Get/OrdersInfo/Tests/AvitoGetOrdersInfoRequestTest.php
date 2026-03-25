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

namespace BaksDev\Avito\Orders\Api\Get\OrdersInfo\Tests;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoDTO;
use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoRequest;
use BaksDev\Avito\Type\Authorization\AvitoTokenAuthorization;
use BaksDev\Avito\Type\Id\AvitoTokenUid;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'test')]
#[Group('avito-orders')]
#[Group('avito-orders-api')]
final class AvitoGetOrdersInfoRequestTest extends KernelTestCase
{
    private static AvitoTokenAuthorization $authorization;

    public static function setUpBeforeClass(): void
    {
        self::$authorization = new AvitoTokenAuthorization(
            token: new AvitoTokenUid(AvitoTokenUid::TEST),
            profile: new UserProfileUid(UserProfileUid::TEST),
            client: $_SERVER['TEST_AVITO_CLIENT'],
            secret: $_SERVER['TEST_AVITO_SECRET'],
            user: $_SERVER['TEST_AVITO_USER'],
            percent: $_SERVER['TEST_AVITO_PERCENT'] ?? '0',
        );
    }

    public function testGet(): void
    {
        /** @var AvitoGetOrdersInfoRequest $AvitoGetOrdersInfoRequest */
        $AvitoGetOrdersInfoRequest = self::getContainer()->get(AvitoGetOrdersInfoRequest::class);
        $AvitoGetOrdersInfoRequest->tokenHttpClient(self::$authorization);

        $ordersInfo = $AvitoGetOrdersInfoRequest
            ->interval('10 days')
            ->findAll();

        foreach($ordersInfo as $item)
        {
            self::assertInstanceOf(AvitoGetOrdersInfoDTO::class, $item);

            // Вызываем все геттеры
            $reflectionClass = new ReflectionClass(AvitoGetOrdersInfoDTO::class);
            $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach($methods as $method)
            {
                // Методы без аргументов
                if($method->getNumberOfParameters() === 0)
                {
                    // Вызываем метод
                    $data = $method->invoke($item);
                    //dump($data);
                }
            }

            return;
        }
    }
}