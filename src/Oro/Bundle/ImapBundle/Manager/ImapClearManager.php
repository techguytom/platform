<?php

namespace Oro\Bundle\ImapBundle\Manager;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Doctrine\ORM\EntityManager;

use Oro\Bundle\EmailBundle\Entity\EmailUser;
use Oro\Bundle\ImapBundle\Entity\ImapEmailFolder;
use Oro\Bundle\ImapBundle\Entity\UserEmailOrigin;

/**
 * Class ImapClearManager
 *
 * @package Oro\Bundle\EmailBundle\Manager
 */
class ImapClearManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const BATCH_SIZE = 50;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * Constructor.
     *
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * @param $originId
     *
     * @return bool
     */
    public function clear($originId)
    {
        $origins = $this->getOriginsToClear($originId);
        if (!$origins) {
            $this->logger->notice('Nothing to clear');

            return false;
        }
        foreach ($origins as $origin) {
            $this->logger->notice(sprintf('Clearing origin: %s, %s', $origin->getId(), $origin));

            $this->clearOrigin($origin);

            $this->logger->notice('Origin processed successfully');
        }

        return true;
    }

    /**
     * @param int $originId
     *
     * @return UserEmailOrigin[]
     * @throws \Exception
     */
    protected function getOriginsToClear($originId)
    {
        $originRepository = $this->em->getRepository('OroImapBundle:UserEmailOrigin');

        if ($originId !== null) {
            /** @var UserEmailOrigin $origin */
            $origin = $originRepository->find($originId);
            if ($origin === null) {
                $this->logger->notice(sprintf('Origin with ID %s does not exist', $originId));

                return [];
            }

            $origins = [$origin];
        } else {
            $origins = $originRepository->findAll();
        }

        return $origins;
    }

    /**
     * @param UserEmailOrigin $origin
     */
    protected function clearOrigin($origin)
    {
        $folders = $origin->getFolders();
        $folderRepository = $this->em->getRepository('OroImapBundle:ImapEmailFolder');

        foreach ($folders as $folder) {
            $imapFolder = $folderRepository->findOneBy(['folder' => $folder]);
            if ($imapFolder && !$origin->isActive()) {
                $this->clearFolder($imapFolder);
                $this->em->remove($imapFolder);
                $this->logger->notice(sprintf('ImapFolder with ID %s removed', $imapFolder->getId()));
            } elseif ($imapFolder && !$folder->isSyncEnabled()) {
                $this->clearFolder($imapFolder);
                $imapFolder->getFolder()->setSynchronizedAt(null);
            }
        }
        foreach ($folders as $folder) {
            if (!$origin->isActive()) {
                $this->em->remove($folder);
                $this->logger->notice(sprintf('Folder with ID %s removed', $folder->getId()));
            }
        }

        if (!$origin->isActive()) {
            $this->em->remove($origin);
            $this->logger->notice(sprintf('Origin with ID %s removed', $origin->getId()));
        }

        $this->em->flush();
    }

    /**
     * @param ImapEmailFolder $imapFolder
     */
    protected function clearFolder($imapFolder)
    {
        $folder = $imapFolder->getFolder();
        $query = $this->em->getRepository('OroEmailBundle:EmailUser')
            ->getEmailUserByFolder($folder)
            ->getQuery();
        $iterableResult = $query->iterate();

        $i = 0;
        while (($row = $iterableResult->next()) !== false) {
            /** @var EmailUser $emailUser */
            $emailUser = $row[0];
            $email = $emailUser->getEmail();
            $this->em->remove($emailUser);

            $imapEmails = $this->em->getRepository('OroImapBundle:ImapEmail')->findBy([
                'email' => $email,
                'imapFolder' => $imapFolder,
            ]);
            foreach ($imapEmails as $imapEmail) {
                $this->em->remove($imapEmail);
            }

            if (($i % self::BATCH_SIZE) === 0) {
                $this->em->flush();
                $this->em->clear('OroEmailBundle:EmailUser');
                $this->em->clear('OroImapBundle:ImapEmail');
            }
            ++$i;
        }
        if ($i > 0) {
            $this->logger->notice(
                sprintf(
                    'ImapFolder with ID %s cleared. Removed %d emails.',
                    $imapFolder->getId(),
                    $i
                )
            );
        }

        $this->em->flush();
    }
}
