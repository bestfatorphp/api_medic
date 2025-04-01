<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Общий для асинхронных методов
 * @method static array getTaskResult(string $taskUuid)                                         Метод подходит для методов, где используется подготовка данных. В параметре возвращается название метода, по которому выполняется подготовка задания.
 *
 * Методы: получение статистики:
 * @method static array getCampaignCommonStats(int $campaignId)                                 Получает общую статистику по email-рассылке
 * @method static array getCampaignDeliveryStats(int $campaignId, array $options = [])          Запрашивает статистику доставки email-рассылки (асинхронный метод)
 * @method static array getCampaignStatus(int $campaignId)                                      Получает текущий статус email-рассылки
 * @method static array getMessages(string $dateFrom, string $dateTo, array $options = [])      Получает список сообщений за указанный период
 * @method static array getVisitedLinks(int $campaignId, bool $group = true)                    Получает статистику посещенных ссылок по email-рассылки
 * @method static array listMessages(string $dateFrom, string $dateTo, array $options = [])     Получает список сообщений за указанный период
 * @method static array getCampaigns(array $options = [])                                       Получает список рассылок с возможностью фильтрации по дате и пагинацией
 * @method static array getMessage(int|array $messageId)                                        Получает информацию о сообщении по его ID
 *
 * Методы: работа со списками контактов:
 * @method static array createList(string $title, array $options = [])                                          Создает новый список контактов
 * @method static array deleteList(int $listId)                                                                 Удаляет список рассылки
 * @method static array exclude(string $contactType, string $contact, string|array|null $listIds = null)        Исключает контакт из списков рассылки
 * @method static array exportContacts(int $listId, array $fieldNames, array $options = [])                     Экспортирует контакты из указанного списка
 * @method static array getContactCount(int $listId, array $params = [])                                        Получает количество контактов в списке с возможностью фильтрации
 * @method static array getLists()                                                                              Получает список всех рассылок аккаунта
 * @method static array getTotalContactsCount(string $login)                                                    Получает общее количество контактов в аккаунте
 * @method static array importContacts(array $fieldNames, array $data, array $options = [])                     Импортирует или обновляет контакты с комплексной валидацией
 * @method static array subscribe(array $fields, string|array $listIds, array $options = [])                    Подписывает контакт на указанные списки рассылки, а также позволяет добавить/поменять значения дополнительных полей и меток
 * @method static array unsubscribe(string $contactType, string $contact, string|array|null $listIds = null)    Отписывает контакт от указанных списков рассылки
 * @method static array updateList(int $listId, array $options = [])                                            Обновляет параметры существующего списка рассылки
 * @method static array isContactInLists(string $email, array|string $listIds, string $condition = 'or')        Проверяет наличие email в указанных списках
 * @method static array getContact(string $email, array $options = [])                                          Получает информацию о контакте по email
 *
 * Методы: создание и отправка сообщений:
 * @method static array cancelCampaign(int $campaignId)                                                         Отменяет запланированную или запущенную рассылку
 * @method static array checkEmail(int|array $emailIds)                                                         Проверяет статус отправки email-сообщений
 * @method static array checkSms(int|string $smsId)                                                             Проверяет статус доставки SMS-сообщения
 * @method static array createCampaign(int $messageId, array $options = [])                                     Создает и запускает рассылку
 * @method static array createEmailMessage(array $params)                                                       Создает email-сообщение для рассылки
 * @method static array createSmsMessage(string $sender, string $body, int $listId, ?string $tag = null)        Создает SMS-сообщение для рассылки
 * @method static array deleteMessage(int $messageId)                                                           Удаляет сообщение (email или SMS) из системы
 * @method static array getActualMessageVersion(int $messageId)                                                 Получает ID актуальной версии письма
 * @method static array getWebVersion(int $campaignId, string $format = 'json')                                 Получает веб-версию письма существующей рассылки
 * @method static array sendEmail(string $email, string $senderName, string $senderEmail, string $subject, string $body, int $listId, array $options = [])
 * @method static array sendSms(string|array $phones, string $sender, string $text)
 * @method static array sendTestEmail(int $letterId, string|array $emails)                                      Отправляет тестовое (которое уже в системе) email-сообщение на указанные адреса
 * @method static array updateEmailMessage(int $messageId, array $params = [])                                  Обновляет существующее email-сообщение
 * @method static array updateOptInEmail(string $senderName, string $senderEmail, string $subject, string $body, int $listId) Обновляет письмо двойного подтверждения подписки на рассылку или подтверждение пароля
 * @method static array getSenderDomainList(string $username, array $options = [])                              Получает список доменов отправителей для указанного пользователя
 *
 * Методы: работа с шаблонами:
 * @method static array createEmailTemplate(string $title, string $subject, string $body, array $options = [])          Создает новый шаблон email-сообщения
 * @method static array deleteTemplate(int $templateId)                                                                 Удаляет шаблон письма
 * @method static array getTemplate(?int $templateId = null, ?int $systemTemplateId = null, string $format = 'json')    Получает информацию о шаблоне письма
 * @method static array getTemplates(array $options = [])                                                               Получает список шаблонов писем с возможностью фильтрации
 * @method static array listTemplates(array $options = [])                                                      Получает список шаблонов с возможностью фильтрации и пагинации
 * @method static array updateEmailTemplate(int $templateId, array $params = [])                                Обновляет существующий шаблон email-сообщения
 *
 * Методы: работа с заметками:
 * @method static array createSubscriberNote(int $subscriberId, string $content)                                Создает заметку для подписчика
 * @method static array updateSubscriberNote(int $noteId, string $content, string $format = 'json')             Обновляет существующую заметку подписчика
 * @method static array deleteSubscriberNote(int $noteId, string $format = 'json')                              Удаляет заметку подписчика
 * @method static array getSubscriberNote(int $noteId, string $format = 'json')                                 Получает информацию о конкретной заметке подписчика
 * @method static array getSubscriberNotes(int $subscriberId, array $options = [])                              Получает список заметок подписчика с возможностью сортировки и фильтрации
 *
 * Методы: работа с дополнительными полями и метками:
 * @method static array createField(string $name, string $type, ?string $publicName = null)                     Создает новое пользовательское поле
 * @method static array deleteField(int $fieldId)                                                               Удаляет пользовательское поле
 * @method static array deleteTag(int $tagId)                                                                   Удаляет тег
 * @method static array getFields()                                                                             Получает список пользовательских полей
 * @method static array getTags()                                                                               Получает список тегов
 * @method static array updateField(int $id, string $name, ?string $publicName = null)                          Обновляет пользовательское поле
 * @method static array getContactFieldValues(string $email, array|string $fieldIds)                            Получает значения дополнительных полей контакта
 *
 * Вспомогательные методы:
 * @method static bool checkApiConnection()
 *
 * @see \App\Services\UniSenderService
 */
class UniSender extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\UniSenderService::class;
    }
}
