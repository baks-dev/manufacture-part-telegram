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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\Done;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Manufacture\Part\Entity\ManufacturePart;
use BaksDev\Manufacture\Part\Repository\ActiveManufacturePartByProduct\ActiveManufacturePartByProductInterface;
use BaksDev\Manufacture\Part\Repository\ActiveWorkingManufacturePart\ActiveWorkingManufacturePartInterface;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartDone;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartWorking;
use BaksDev\Manufacture\Part\UseCase\Admin\Action\ManufacturePartActionDTO;
use BaksDev\Manufacture\Part\UseCase\Admin\Action\ManufacturePartActionHandler;
use BaksDev\Manufacture\Part\UseCase\Admin\Working\WorkingManufacturePartDTO;
use BaksDev\Manufacture\Part\UseCase\Admin\Working\WorkingManufacturePartHandler;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\Callback\TelegramCallbackMessage;
use BaksDev\Telegram\Bot\Repository\UsersTableTelegramSettings\GetTelegramBotSettingsInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 100)]
final class TelegramManufacturePartDone
{
    private TelegramSendMessage $telegramSendMessage;
    private GetTelegramBotSettingsInterface $settings;
    private EntityManagerInterface $entityManager;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;
    private ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart;
    private ManufacturePartActionHandler $ManufacturePartActionHandler;

    public function __construct(
        #[TaggedIterator('baks.reference.choice')] iterable $reference,
        EntityManagerInterface $entityManager,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        TelegramSendMessage $telegramSendMessage,
        GetTelegramBotSettingsInterface $settings,
        ManufacturePartActionHandler $ManufacturePartActionHandler,

    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->settings = $settings;
        $this->entityManager = $entityManager;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
        $this->activeWorkingManufacturePart = $activeWorkingManufacturePart;
        $this->ManufacturePartActionHandler = $ManufacturePartActionHandler;
    }

    /**
     * Выполняем действие сотрудника
     */
    public function __invoke(TelegramCallbackMessage $message): void
    {


        if(!$message->getClass() instanceof ManufacturePartDone)
        {
            return;
        }

        /**
         * Получаем заявку на производство
         */
        $ManufacturePart = $this->entityManager->getRepository(ManufacturePart::class)
            ->find($message->getClass());

        if($ManufacturePart)
        {

            /** Сбрасываем идентификатор */
            $ApcuAdapter = new ApcuAdapter('TelegramBot');
            $ApcuAdapter->delete('identifier-'.$message->getChat());

            /** Возвращает пользователя на производство */
            $TelegramCallback = $ApcuAdapter->getItem('callback-'.$message->getChat());
            $TelegramCallback->set(ManufacturePartWorking::class);
            $TelegramCallback->expiresAfter(60 * 60 * 24);
            $ApcuAdapter->save($TelegramCallback);


            /** Получаем активный профиль пользователя чата */
            $UserProfileUid = $this->activeProfileByAccountTelegram
                ->getActiveProfileUidOrNullResultByChat($message->getChat());

            if(!$UserProfileUid === null)
            {
                /** Сбрасываем состояние диалога */
                $ApcuAdapter->delete('callback-'.$message->getChat());
                return;
            }


            /** Присваиваем настройки Telegram */

            $settings = $this->settings->settings();
            $this->telegramSendMessage
                ->token($settings->getToken())
                ->chanel($message->getChat());


            /**
             * Получаем активное рабочее состояние производственной партии
             */
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


            /**
             * Делаем отметку о выполнении этапа производства
             */
            $ManufacturePartActionDTO = new ManufacturePartActionDTO($ManufacturePart->getEvent());
            $ManufacturePartWorkingDTO = $ManufacturePartActionDTO->getWorking();
            $ManufacturePartWorkingDTO->setWorking($UsersTableActionsWorkingUid);
            $ManufacturePartWorkingDTO->setProfile($UserProfileUid);

            $ManufacturePartHandler = $this->ManufacturePartActionHandler->handle($ManufacturePartActionDTO);

            if(!$ManufacturePartHandler instanceof ManufacturePart)
            {
                throw new DomainException(sprintf('Ошибка %s при обновлении этапа производства', $ManufacturePartHandler));
            }

            /**
             * Отправляем уведомление пользователю
             */
            $messageHandler = "<b>Выполненный этап производства:</b>\n";
            $messageHandler .= sprintf("%s\n", $ManufacturePart->getNumber()); // номер партии
            $messageHandler .= sprintf("Дата %s\n", (new DateTimeImmutable())->format('d.m.Y H:i')); // Дата выполненного этапа
            $messageHandler .= sprintf("%s <b>%s шт.</b>", $UsersTableActionsWorkingUid->getAttr(), $ManufacturePart->getQuantity()); // Этап производства

            /** Отправляем сообщение об успешном выполнении этапа */
            $this->telegramSendMessage
                ->message($messageHandler)
                ->send();

        }
    }
}