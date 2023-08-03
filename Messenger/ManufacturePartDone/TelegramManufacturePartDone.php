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

namespace BaksDev\Manufacture\Part\Telegram\Messenger\TelegramManufacturePartDone;

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Manufacture\Part\Entity\Event\ManufacturePartEvent;
use BaksDev\Manufacture\Part\Entity\ManufacturePart;
use BaksDev\Manufacture\Part\Repository\ActiveManufacturePartByProduct\ActiveManufacturePartByProductInterface;
use BaksDev\Manufacture\Part\Repository\ActiveWorkingManufacturePart\ActiveWorkingManufacturePartInterface;
use BaksDev\Manufacture\Part\Repository\AllWorkingByManufacturePart\AllWorkingByManufacturePartInterface;
use BaksDev\Manufacture\Part\Type\Id\ManufacturePartUid;
use BaksDev\Manufacture\Part\Type\Telegram\ManufacturePartDone;
use BaksDev\Manufacture\Part\UseCase\Admin\Working\WorkingManufacturePartDTO;
use BaksDev\Manufacture\Part\UseCase\Admin\Working\WorkingManufacturePartHandler;
use BaksDev\Products\Category\Type\Id\ProductCategoryUid;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\Callback\TelegramCallbackMessage;
use BaksDev\Telegram\Bot\Repository\UsersTableTelegramSettings\GetTelegramBotSettingsInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler(priority: 100)]
final class TelegramManufacturePartDone
{
    private TelegramSendMessage $telegramSendMessage;
    private GetTelegramBotSettingsInterface $settings;
    private EntityManagerInterface $entityManager;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;
    private AllWorkingByManufacturePartInterface $allWorkingByManufacturePart;
    private TranslatorInterface $translator;
    private WorkingManufacturePartHandler $workingManufacturePartHandler;
    private ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart;
    private ActiveManufacturePartByProductInterface $activeManufacturePartByProduct;
    private iterable $reference;

    public function __construct(
        #[TaggedIterator('baks.reference.choice')] iterable $reference,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        ActiveWorkingManufacturePartInterface $activeWorkingManufacturePart,
        TelegramSendMessage $telegramSendMessage,
        GetTelegramBotSettingsInterface $settings,
        AllWorkingByManufacturePartInterface $allWorkingByManufacturePart,
        WorkingManufacturePartHandler $workingManufacturePartHandler,
        ActiveManufacturePartByProductInterface $activeManufacturePartByProduct,
    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->settings = $settings;
        $this->entityManager = $entityManager;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
        $this->activeWorkingManufacturePart = $activeWorkingManufacturePart;
        $this->allWorkingByManufacturePart = $allWorkingByManufacturePart;
        $this->reference = $reference;
        $this->translator = $translator;
        $this->workingManufacturePartHandler = $workingManufacturePartHandler;
        $this->activeManufacturePartByProduct = $activeManufacturePartByProduct;
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
         * Получаем заявку на производство (незавершенную, самую старую) по идентификатору продукта
         */
        $ManufacturePart = $this->activeManufacturePartByProduct->findManufacturePartByProduct($message->getClass());


        if($ManufacturePart)
        {

            $ApcuAdapter = new ApcuAdapter('TelegramBot');

            /** Сбрасываем идентификатор */
            $ApcuAdapter->delete('identifier-'.$message->getChat());

            /** Возвращает пользователя на производство */
            $TelegramCallback = $ApcuAdapter->getItem('callback-'.$message->getChat());
            $TelegramCallback->set(ManufacturePartUid::class);
            $TelegramCallback->expiresAfter(60 * 60 * 24);
            $ApcuAdapter->save($TelegramCallback);

            /** Возвращает пользователя на производство */
            //$ApcuAdapter->delete('callback-'.$message->getChat());
//            $ApcuAdapter->get('callback-'.$message->getChat(), function(ItemInterface $item) {
//                $item->expiresAfter(60 * 60 * 24);
//                return ManufacturePartUid::class;
//            });


            /**
             * Получаем активный профиль пользователя чата
             */
            $UserProfileUid = $this->activeProfileByAccountTelegram->getActiveProfileUidOrNullResultByChat($message->getChat());

            if(!$UserProfileUid === null)
            {
                /** Сбрасываем состояние диалога */
                $ApcuAdapter->delete('identifier-'.$message->getChat());
                $ApcuAdapter->delete('callback-'.$message->getChat());

                return;
            }


            /** Присваиваем настройки Telegram */

            $settings = $this->settings->settings();
            $this->telegramSendMessage
                ->token($settings->getToken())
                ->chanel($message->getChat());

            /**
             * Получаем информацию о партии производства со всеми выполненными действиями
             */

            $ManufacturePartProduct = $this->allWorkingByManufacturePart
                ->fetchAllWorkingByManufacturePartAssociative($ManufacturePart->getId());
            $CurrentManufacturePart = current($ManufacturePartProduct);

            /** Если имеется хотя бы одно действие */
            if($CurrentManufacturePart)
            {
                /** Получаем активное рабочее состояние */
                $UsersTableActionsWorkingUid = $this->activeWorkingManufacturePart
                    ->findNextWorkingByManufacturePart(
                        $ManufacturePart->getId(),
                        new ProductCategoryUid($CurrentManufacturePart['product_category_id'])
                    );
            }



            if(!$CurrentManufacturePart || !$UsersTableActionsWorkingUid)
            {
                /** Отправляем сообщение о выполненной заявке  */
                $this->telegramSendMessage
                    ->message('Заявка выполнена')
                    ->send();

                return;
            }


            /** Если торговое предложение Справочник - ищем домен переводов */
            if($CurrentManufacturePart['product_offer_reference'])
            {
                foreach($this->reference as $reference)
                {
                    if($reference->type() === $CurrentManufacturePart['product_offer_reference'])
                    {
                        $CurrentManufacturePart['product_offer_value'] = $this->translator->trans($CurrentManufacturePart['product_offer_value'], domain: $reference->domain());

                    }
                }
            }

            /** Если множественный вариант Справочник - ищем домен переводов */
            if($CurrentManufacturePart['product_variation_reference'])
            {
                foreach($this->reference as $reference)
                {
                    if($reference->type() === $CurrentManufacturePart['product_variation_reference'])
                    {
                        $CurrentManufacturePart['product_variation_value'] = $this->translator->trans($CurrentManufacturePart['product_variation_value'], domain: $reference->domain());

                    }
                }
            }

            /** Если модификатор Справочник - ищем домен переводов */
            if($CurrentManufacturePart['product_modification_reference'])
            {
                foreach($this->reference as $reference)
                {
                    if($reference->type() === $CurrentManufacturePart['product_modification_reference'])
                    {
                        $CurrentManufacturePart['product_modification_value'] = $this->translator->trans($CurrentManufacturePart['product_modification_value'], domain: $reference->domain());

                    }
                }
            }


            /** Делаем отметку о выполнении этапа производства */
            $quantity = $CurrentManufacturePart['part_quantity'] ?: $CurrentManufacturePart['part_total'];


            /** Название продукта */
            $productName = $CurrentManufacturePart['product_name']."\n";
            $productName .= $CurrentManufacturePart['product_offer_value'].' ' ?: '';
            $productName .= $CurrentManufacturePart['product_variation_value'].' ' ?: '';
            $productName .= $CurrentManufacturePart['product_modification_value'].' ' ?: '';


            /** Получаем активное событие заявки на производство  */
            $WorkingManufacturePartDTO = new WorkingManufacturePartDTO();
            $ManufacturePartEvent = $this->entityManager->getRepository(ManufacturePartEvent::class)
                ->find($ManufacturePart->getEvent());
            $ManufacturePartEvent->getDto($WorkingManufacturePartDTO);

            $ManufacturePartWorkingDTO = $WorkingManufacturePartDTO->getWorking();
            $ManufacturePartWorkingDTO->setWorking($UsersTableActionsWorkingUid);
            $ManufacturePartWorkingDTO->setQuantity($quantity);
            $ManufacturePartWorkingDTO->setProfile($UserProfileUid);

            $ManufacturePartHandler = $this->workingManufacturePartHandler->handle($WorkingManufacturePartDTO);

            if(!$ManufacturePartHandler instanceof ManufacturePart)
            {
                throw new DomainException(sprintf('Ошибка %s при обновлении этапа производства', $ManufacturePartHandler));
            }

            $messageHandler = "<b>Выполненный этап производства:</b>\n";
            $messageHandler .= sprintf("%s\n", $productName); // название продукта
            $messageHandler .= sprintf("Дата %s\n", (new DateTimeImmutable())->format('d.m.Y H:i')); // Дата выполненного этапа
            $messageHandler .= sprintf("%s <b>%s шт.</b>", $UsersTableActionsWorkingUid->getAttr(), $ManufacturePartWorkingDTO->getQuantity()); // Этап производства

            /** Отправляем сообщение об успешном выполнении этапа */
            $this->telegramSendMessage
                ->message($messageHandler)
                ->send();

        }

    }
}