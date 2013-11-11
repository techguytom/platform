<?php

namespace Oro\Bundle\CalendarBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Oro\Bundle\CalendarBundle\Entity\Calendar;
use Oro\Bundle\CalendarBundle\Entity\CalendarConnection;
use Oro\Bundle\CalendarBundle\Entity\CalendarEvent;
use Oro\Bundle\CalendarBundle\Notification\RemindTimeCalculator;
use Oro\Bundle\UserBundle\Entity\User;

class EntitySubscriber implements EventSubscriber
{
    /**
     * @var RemindTimeCalculator
     */
    protected $remindTimeCalculator;

    /**
     * Constructor
     *
     * @param RemindTimeCalculator $remindTimeCalculator
     */
    public function __construct(RemindTimeCalculator $remindTimeCalculator)
    {
        $this->remindTimeCalculator = $remindTimeCalculator;
    }

    /**
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            //@codingStandardsIgnoreStart
            Events::prePersist,
            Events::preUpdate,
            Events::onFlush
            //@codingStandardsIgnoreEnd
        );
    }

    /**
     * @param LifecycleEventArgs $event
     */
    public function prePersist(LifecycleEventArgs $event)
    {
        $entity = $event->getEntity();
        if ($entity instanceof CalendarEvent) {
            if ($entity->getReminder()) {
                $entity->setRemindAt($this->remindTimeCalculator->calculateRemindAt($entity->getStart()));
            }
            $entity->setReminded(false);
        }
    }

    /**
     * @param PreUpdateEventArgs $event
     */
    public function preUpdate(PreUpdateEventArgs $event)
    {
        $entity = $event->getEntity();
        if ($entity instanceof CalendarEvent) {
            if ($event->hasChangedField('start') || $event->hasChangedField('reminder')) {
                $needChangeSetsComputing = false;
                if ($event->hasChangedField('reminder')) {
                    if ($event->getNewValue('reminder')) {
                        $start = $event->hasChangedField('start')
                            ? $event->getNewValue('start')
                            : $entity->getStart();
                        $entity->setRemindAt(
                            $this->remindTimeCalculator->calculateRemindAt($start)
                        );
                    } else {
                        $entity->setRemindAt(null);
                    }
                    if ($entity->getReminded()) {
                        $entity->setReminded(false);
                    }
                    $needChangeSetsComputing = true;
                } else {
                    if ($entity->getReminder()) {
                        $entity->setRemindAt(
                            $this->remindTimeCalculator->calculateRemindAt($event->getNewValue('start'))
                        );
                        $needChangeSetsComputing = true;
                    }
                    if ($entity->getReminded()) {
                        $entity->setReminded(false);
                        $needChangeSetsComputing = true;
                    }
                }
                if ($needChangeSetsComputing) {
                    $em  = $event->getEntityManager();
                    $uow = $em->getUnitOfWork();
                    $uow->recomputeSingleEntityChangeSet(
                        $em->getClassMetadata('OroCalendarBundle:CalendarEvent'),
                        $entity
                    );
                }
            }
        }
    }

    /**
     * @param OnFlushEventArgs $event
     */
    public function onFlush(OnFlushEventArgs $event)
    {
        $em                      = $event->getEntityManager();
        $uow                     = $em->getUnitOfWork();
        $needChangeSetsComputing = false;
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof User) {
                // create a default calendar to a new user
                $calendar = new Calendar();
                $calendar->setOwner($entity);
                // connect the calendar to itself
                $calendar->addConnection(new CalendarConnection($calendar));
                $em->persist($calendar);
                $needChangeSetsComputing = true;
            }
        }
        if ($needChangeSetsComputing) {
            $uow->computeChangeSets();
        }
    }
}