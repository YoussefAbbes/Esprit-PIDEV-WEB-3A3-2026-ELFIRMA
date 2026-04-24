<?php
namespace App\EventSubscriber;

use CalendarBundle\Entity\Event;
use CalendarBundle\Event\SetDataEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Repository\MaintenanceRepository;

class CalendarSubscriber implements EventSubscriberInterface
{
    private $repo;

    public function __construct(MaintenanceRepository $repo)
    {
        $this->repo = $repo;
    }

    public static function getSubscribedEvents()
    {
        return [
            SetDataEvent::class => 'onCalendarSetData'
        ];
    }

    public function onCalendarSetData(SetDataEvent $event)
    {
        $maintenances = $this->repo->findAll();

        foreach ($maintenances as $m) {
            $event->addEvent(
                new Event(
                    $m->getTypeM(),
                    $m->getDateM(),
                    $m->getDateM()
                )
            );
        }
    }
}