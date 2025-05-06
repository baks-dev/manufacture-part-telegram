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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\TelegramManufacturePart;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Manufacture\Part\Entity\Event\ManufacturePartEvent;
use BaksDev\Manufacture\Part\Entity\Invariable\ManufacturePartInvariable;
use BaksDev\Manufacture\Part\Entity\ManufacturePart;
use BaksDev\Manufacture\Part\Repository\ActiveWorkingManufacturePart\ActiveWorkingManufacturePartInterface;
use BaksDev\Manufacture\Part\Repository\ManufacturePartCurrentEvent\ManufacturePartCurrentEventInterface;
use BaksDev\Manufacture\Part\Telegram\Repository\ExistManufacturePart\ExistManufacturePartInterface;
use BaksDev\Manufacture\Part\UseCase\Admin\Action\ManufacturePartActionDTO;
use BaksDev\Manufacture\Part\UseCase\Admin\Action\ManufacturePartActionHandler;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\UsersTable\Type\Actions\Working\UsersTableActionsWorkingUid;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TelegramManufacturePartDone
{
    public const string KEY = 'PHDHkJV';

    public function __construct(
        #[Target('manufacturePartTelegramLogger')] private LoggerInterface $logger,
        private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        private ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        private TelegramSendMessages $telegramSendMessage,
        private ManufacturePartActionHandler $ManufacturePartActionHandler,
        private Security $security,
        private ManufacturePartCurrentEventInterface $ManufacturePartCurrentEvent,
        private ExistManufacturePartInterface $ExistManufacturePart,
    ) {}

    /**
     * Выполняем действие сотрудника и отправляем в ответ сообщение
     */
    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestCallback $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(
            false === ($TelegramRequest instanceof TelegramRequestCallback) ||
            empty($TelegramRequest->getIdentifier()) ||
            $TelegramRequest->getCall() !== self::KEY ||
            !$this->security->isGranted('ROLE_USER')
        )
        {
            return;
        }

        /**
         * Проверяем что имеется производственная партия
         */

        $isExist = $this->ExistManufacturePart
            ->forPart($TelegramRequest->getIdentifier())
            ->isExist();

        if(false === $isExist)
        {
            return;
        }

        /**
         * Проверяем, что профиль пользователя чата активный
         */

        $UserProfileUid = $this->activeProfileByAccountTelegram
            ->findByChat($TelegramRequest->getChatId());

        if(false === ($UserProfileUid instanceof UserProfileUid))
        {
            $this->logger->warning('Активный профиль пользователя не найден', [
                __FILE__.''.__LINE__,
                'chat' => $TelegramRequest->getChatId()
            ]);

            return;
        }

        /**
         * TODO: Проверяем, что профиль пользователя чата соответствует правилам доступа
         */

        /**
         * Получаем активное событие производственной партии
         */

        $ManufacturePartEvent = $this->ManufacturePartCurrentEvent
            ->fromPart($TelegramRequest->getIdentifier())
            ->find();

        if(false === ($ManufacturePartEvent instanceof ManufacturePartEvent))
        {
            return;
        }

        if(false === $ManufacturePartEvent->isInvariable())
        {
            return;
        }

        /**
         * Получаем активное рабочее состояние производственной партии
         */

        $UsersTableActionsWorkingUid = $this->activeWorkingManufacturePart
            ->findNextWorkingByManufacturePart($ManufacturePartEvent->getMain());


        if(false === ($UsersTableActionsWorkingUid instanceof UsersTableActionsWorkingUid))
        {
            return;
        }


        /**
         * Делаем отметку о выполнении этапа производства
         */

        $ManufacturePartActionDTO = new ManufacturePartActionDTO();
        $ManufacturePartEvent->getDto($ManufacturePartActionDTO);

        $ManufacturePartActionDTO
            ->getWorking()
            ->setWorking($UsersTableActionsWorkingUid)
            ->setProfile($UserProfileUid);

        $ManufacturePartHandler = $this->ManufacturePartActionHandler->handle($ManufacturePartActionDTO);

        if(false === ($ManufacturePartHandler instanceof ManufacturePart))
        {
            $this->logger->critical(sprintf('Ошибка %s при обновлении этапа производства', $ManufacturePartHandler));
            return;
        }

        /**
         * Отправляем уведомление пользователю о выполненном им этапе
         */

        $messageHandler = '<b>Выполненный этап производственной партии:</b>';
        $messageHandler .= PHP_EOL;
        $messageHandler .= PHP_EOL;
        $messageHandler .= sprintf('Номер: <b>%s</b>', $ManufacturePartEvent->getInvariable()->getNumber()); // номер партии
        $messageHandler .= PHP_EOL;
        $messageHandler .= sprintf('Дата: <b>%s</b>', new DateTimeImmutable()->format('d.m.Y H:i')); // Дата выполненного этапа
        $messageHandler .= PHP_EOL;
        $messageHandler .= sprintf("%s: <b>%s шт.</b>", $UsersTableActionsWorkingUid->getAttr(), $ManufacturePartEvent->getInvariable()->getQuantity()); // Этап производства

        /** Отправляем сообщение об успешном выполнении этапа */
        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete([$TelegramRequest->getId()])
            ->message($messageHandler)
            ->send();
    }
}