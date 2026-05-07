<?php

namespace App\EventSubscriber;
// correction de CalendarSubscriber erreurs de syntaxe et d'importation
use App\Repository\MaintenanceRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CalendarSubscriber implements EventSubscriberInterface
{
    private const SET_DATA_EVENT_CLASS = 'CalendarBundle\\Event\\SetDataEvent';
    private const CALENDAR_EVENT_CLASS = 'CalendarBundle\\Entity\\Event';

    public function __construct(private MaintenanceRepository $repo) {}

    public static function getSubscribedEvents(): array
    {
        if (!class_exists(self::SET_DATA_EVENT_CLASS)) {
            return [];
        }

        return [
            self::SET_DATA_EVENT_CLASS => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(object $event): void
    {
        if (!method_exists($event, 'addEvent') || !class_exists(self::CALENDAR_EVENT_CLASS)) {
            return;
        }

        $calendarEventClass = self::CALENDAR_EVENT_CLASS;
        $maintenances = $this->repo->findAll();

        foreach ($maintenances as $m) {
            $event->addEvent(
                new $calendarEventClass(
                    $m->getTypeM(),
                    $m->getDateM(),
                    $m->getDateM()
                )
            );
        }
    }
}