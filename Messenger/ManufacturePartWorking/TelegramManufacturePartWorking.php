<?php
/*
 *  Copyright 2023.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\ManufacturePartWorking;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Manufacture\Part\Entity\ManufacturePart;
use BaksDev\Manufacture\Part\Repository\ActiveWorkingManufacturePart\ActiveWorkingManufacturePartInterface;
use BaksDev\Manufacture\Part\Repository\AllWorkingByManufacturePart\AllWorkingByManufacturePartInterface;
use BaksDev\Manufacture\Part\Telegram\Messenger\TelegramManufacturePartDone\TelegramManufacturePartDone;
use BaksDev\Manufacture\Part\Type\Id\ManufacturePartUid;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\Callback\TelegramCallbackMessage;
use BaksDev\Telegram\Bot\Repository\UsersTableTelegramSettings\GetTelegramBotSettingsInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'sync')]
final class TelegramManufacturePartWorking
{
    private TelegramSendMessage $telegramSendMessage;
    private GetTelegramBotSettingsInterface $settings;
    private EntityManagerInterface $entityManager;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;
    private AllWorkingByManufacturePartInterface $allWorkingByManufacturePart;
    private ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart;

    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        TelegramSendMessage $telegramSendMessage,
        GetTelegramBotSettingsInterface $settings,
        AllWorkingByManufacturePartInterface $allWorkingByManufacturePart,

        LoggerInterface $logger,
    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->settings = $settings;
        $this->entityManager = $entityManager;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
        $this->activeWorkingManufacturePart = $activeWorkingManufacturePart;
        $this->allWorkingByManufacturePart = $allWorkingByManufacturePart;

        $this->logger = $logger;
    }

    /**
     * Получаем состояние партии и принимаем количество указанного товара
     */
    public function __invoke(TelegramCallbackMessage $message): void
    {
        if($message->getClass() instanceof ManufacturePartUid)
        {

            $ApcuAdapter = new ApcuAdapter('TelegramBot');

            /**
             * Получаем заявку на производство
             */
            $ManufacturePart = $this->entityManager->getRepository(ManufacturePart::class)->find($message->getClass());

            if($ManufacturePart)
            {

                /** Получаем активный профиль пользователя чата */
                $UserProfileUid = $this->activeProfileByAccountTelegram->getActiveProfileUidOrNullResultByChat($message->getChat());

                if($UserProfileUid === null)
                {
                    /** Сбрасываем идентификатор и callback */
                    $ApcuAdapter->delete('identifier-'.$message->getChat());
                    $ApcuAdapter->delete('callback-'.$message->getChat());

                    return;
                }

                /** Присваиваем настройки Telegram */

                $settings = $this->settings->settings();

                $this->telegramSendMessage
                    ->token($settings->getToken())
                    ->chanel($message->getChat());

                /** Получаем этапы производства указанной производственной партии  */
                $ManufacturePartWorking = $this->allWorkingByManufacturePart
                    ->fetchAllWorkingByManufacturePartAssociative($ManufacturePart->getId());


                /** Получаем активное рабочее состояние производственной партии */
                $UsersTableActionsWorkingUid = $this->activeWorkingManufacturePart
                    ->findNextWorkingByManufacturePart($ManufacturePart->getId());


                if(!$UsersTableActionsWorkingUid)
                {
                    /** Отправляем сообщение о выполненной заявке  */
                    $this->telegramSendMessage
                        ->message('Заявка выполнена')
                        ->send();

                    /** Сбрасываем идентификатор */
                    $ApcuAdapter->delete('identifier-'.$message->getChat());
                    return;
                }

                $caption = "<b>Производство:</b>\n #".$ManufacturePart->getNumber()."\n";


                /** Символ выполненного процесса  */
                $char = "\u2611\ufe0f";
                $decoded = json_decode('["'.$char.'"]');
                $done = mb_convert_encoding($decoded[0], 'UTF-8');

                /** Символ активного процесса  */
                $char = "\u25b6\ufe0f";
                $decoded = json_decode('["'.$char.'"]');
                $right = mb_convert_encoding($decoded[0], 'UTF-8');

                /** Символ НЕвыполненного процесса  */
                $char = "\u2705";
                $decoded = json_decode('["'.$char.'"]');
                $muted = mb_convert_encoding($decoded[0], 'UTF-8');


                $currentWorkingName = null;


                /**
                 * Все действия сотрудников, которые он может выполнить
                 */
                foreach($ManufacturePartWorking as $working)
                {
                    $icon = $currentWorkingName ? $done : $muted;

                    if($UsersTableActionsWorkingUid->equals($working['working_id']))
                    {
                        $currentWorkingName = $working['working_name'];
                        $icon = $right;
                    }

                    $caption .= $icon;
                    $caption .= ' '.$working['working_name'];

                    if($UsersTableActionsWorkingUid->equals($working['working_id']))
                    {
                        $caption .= '<b> '.$ManufacturePart->getQuantity().' шт </b>';
                    }

                    $caption .= "\n";
                }


                /**
                 * Комментарий к заявке
                 */

                $CurrentManufacturePart = current($ManufacturePartWorking);

                if($CurrentManufacturePart['part_comment'])
                {
                    $caption .= "\n".$CurrentManufacturePart['part_comment']."\n";
                }

                $caption .= "\nЕсли Вами был найден брак - обратитесь к ответственному за данную производственную партию.";


                $menu[] = [
                    'text' => sprintf('Выполнено "%s" все %s шт.',
                        $currentWorkingName,
                        $ManufacturePart->getQuantity()
                    ),

                    'callback_data' => TelegramManufacturePartDone::class
                ];

                $markup = json_encode([
                    'inline_keyboard' => array_chunk($menu, 1),
                ]);

                $this->telegramSendMessage
                    ->message($caption)
                    ->markup($markup)
                    ->send();

                return;
            }


            /** Отправляем сообщение о выполненной заявке  */
            $response = $this->telegramSendMessage
                ->message("<b>Производство:</b>\nВышлите QR продукта, либо его идентификатор")
                ->send(false);

            /** Сохраняем последнее сообщение */
            $lastMessage = $ApcuAdapter->getItem('last-'.$message->getChat());
            $lastMessage->set($response['result']['message_id']);
            $lastMessage->expiresAfter(86400);
            $ApcuAdapter->save($lastMessage);

            /** Сбрасываем идентификатор */

            $ApcuAdapter->delete('identifier-'.$message->getChat());

        }
    }
}