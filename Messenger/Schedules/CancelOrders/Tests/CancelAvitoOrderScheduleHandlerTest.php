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

namespace BaksDev\Avito\Orders\Messenger\Schedules\CancelOrders\Tests;

use BaksDev\Avito\Orders\Messenger\Schedules\CancelOrders\CancelAvitoOrderScheduleHandler;
use BaksDev\Avito\Orders\Messenger\Schedules\CancelOrders\CancelAvitoOrdersScheduleMessage;
use BaksDev\Avito\Orders\UseCase\New\Tests\NewAvitoOrderHandlerTest;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use PHPUnit\Framework\Attributes\DependsOnClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'test')]
#[Group('avito-orders')]
#[Group('avito-orders-dispatcher')]
final class CancelAvitoOrderScheduleHandlerTest extends KernelTestCase
{
    #[DependsOnClass(NewAvitoOrderHandlerTest::class)]
    public function testDispatcher(): void
    {
        $CancelAvitoOrderScheduleHandler = self::getContainer()->get(CancelAvitoOrderScheduleHandler::class);

        $profileUid = $_SERVER['TEST_PROFILE'] ?? UserProfileUid::TEST;
        $CancelAvitoOrderScheduleMessage = new CancelAvitoOrdersScheduleMessage($profileUid);

        /** @var CancelAvitoOrderScheduleHandler $CancelAvitoOrderScheduleHandler */
        $CancelAvitoOrderScheduleHandler($CancelAvitoOrderScheduleMessage);

        self::assertTrue(true);
    }
}