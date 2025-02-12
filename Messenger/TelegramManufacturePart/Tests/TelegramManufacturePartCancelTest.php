<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\TelegramManufacturePart\Tests;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Yandex\Market\Type\Authorization\YaMarketAuthorizationToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;


/**
 * @group manufacture-part-telegram
 */
#[When(env: 'test')]
class TelegramManufacturePartCancelTest extends KernelTestCase
{
    private static string $HOST;

    public static function setUpBeforeClass(): void
    {
        self::$HOST = $_SERVER['HOST'];
    }

    public function testUseCase(): void
    {
        $data = [
            "update_id" => 483625267,
            "callback_query" => [
                "id" => "5978273655418474754",
                "from" => [
                    "id" => 1391925303,
                    "is_bot" => false,
                    "first_name" => "Michel Angelo",
                    "username" => "angelo_michel",
                    "language_code" => "ru"
                ],
                "message" => [
                    "message_id" => 162,
                    "from" => [
                        "id" => 7437100079,
                        "is_bot" => true,
                        "first_name" => "WhiteSign - Производство",
                        "username" => "RailsBaksBot"
                    ],
                    "chat" => [
                        "id" => 1391925303,
                        "first_name" => "Michel Angelo",
                        "username" => "angelo_michel",
                        "type" => "private"
                    ],
                    "date" => 1739390463,
                    "text" => "Производственная партия:\n\nНомер: 173.937.775.229\nВсего продукции: 5 шт.\n\nПродукция:\n1. FSWHITE-0064-02-L Черный L  | 1 шт.\n2. FSWOMEN-0359-02-XS Черный XS  | 1 шт.\n3. FSWHITE-0531-02-XL Черный XL  | 1 шт.\n4. FSWOMEN-1255-02-XL Черный XL  | 1 шт.\n5. FSWOMEN-0533-02-2XL Черный 2XL  | 1 шт.\n\nЭтапы производства:\n▶️ сборка заказов 5 шт \n☑️ прессование\n☑️ допрессовка\n☑️ складывание\n☑️ упаковка\n☑️ маркировка\n\nЗаявка зафиксированная за Вами! Для сброса фиксации перейдите в начало меню.\n\nЕсли Вами был найден брак - обратитесь к ответственному за данную производственную партию.",
                    "entities" => [
                        [
                            "offset" => 0,
                            "length" => 24,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 33,
                            "length" => 15,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 66,
                            "length" => 5,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 73,
                            "length" => 10,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 117,
                            "length" => 5,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 158,
                            "length" => 5,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 199,
                            "length" => 5,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 240,
                            "length" => 5,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 283,
                            "length" => 5,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 290,
                            "length" => 19,
                            "type" => "bold"
                        ],
                        [
                            "offset" => 328,
                            "length" => 5,
                            "type" => "bold"
                        ]
                    ],
                    "reply_markup" => [
                        "inline_keyboard" => [
                            [
                                [
                                    "text" => "Отмена",
                                    "callback_data" => "manufacture-part-cancel|0194fafe-3911-7cdf-b1ae-245469b6b1c8"
                                ]
                            ],
                            [
                                [
                                    "text" => "Выполнено \"сборка заказов\" все 5 шт.",
                                    "callback_data" => "manufacture-part-done|0194fafe-3911-7cdf-b1ae-245469b6b1c8"
                                ]
                            ]
                        ]
                    ]
                ],
                "chat_instance" => "-7252352624369181599",
                "data" => "manufacture-part-cancel|0194fafe-3911-7cdf-b1ae-245469b6b1c8"
            ]
        ];


        /** @var HttpClient $HttpClient */
        $HttpClient = self::getContainer()->get(HttpClientInterface::class);

        $HttpClient->request(
            'GET',
            sprintf('https://%s/telegram/manufacture-part/cancel', self::$HOST),
            ['json' => $data],

        );

    }

}