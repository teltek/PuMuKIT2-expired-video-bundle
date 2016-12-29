<?php

namespace Pumukit\ExpiredVideoBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputOption;
use Pumukit\ExpiredVideoBundle\Services\ExpiredVideoService;

class ExpiredVideoRemoveOwnerCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $mmobjRepo = null;
    private $user_code;
    private $type = 'removeOwner';

    private $factoryService;
    private $logger;

    protected function configure()
    {
        $this
            ->setName('video:expired:remove')
            ->setDescription('This command delete role owner when the video was timed out')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter force the execution of this action')
            ->setHelp(<<<'EOT'
Expired video remove delete owner people on multimedia object id when the expiration_date is less than now. This command send email to web administrator when delete data.
EOT
            );
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->expiredVideoService = $this->getContainer()->get('pumukit_expired_video.notification');

        $this->user_code = $this->getContainer()->get('pumukitschema.person')->getPersonalScopeRoleCode();

        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->notificationParameters = $this->getContainer()->getParameter('pumukit_notification');
        $this->sendMail = $this->notificationParameters['sender_email'];

        $this->roleRepo = $this->dm->getRepository('PumukitSchemaBundle:Role');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        if ($input->getOption('force')) {
            $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();

            $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
            $mmobjExpired = $this->getExpiredVideos();

            $expiredOwnerRole = $this->getRoleWithCode('expired_owner');
            if ($mmobjExpired) {
                $aMultimediaObject = array();
                foreach ($mmobjExpired as $mmObj) {
                    $removeOwner = false;
                    foreach ($mmObj->getRoles() as $role) {
                        if ($role->getCod() == $this->user_code) {
                            foreach ($mmObj->getPeopleByRoleCod($this->user_code, true) as $person) {

                                $mmObj->addPersonWithRole($person, $expiredOwnerRole);
                                $mmObj->removePersonWithRole($person, $role);
                            }
                            $removeOwner = true;
                            $this->dm->flush();
                        }
                    }
                    if ($removeOwner) {
                        $aMultimediaObject[] = $mmObj->getId();
                        $output->writeln('Remove owner people from multimedia object id - '.$mmObj->getId());
                    }
                }
                try {
                    $this->expiredVideoService->generateNotification($this->sendMail, $this->type, $mmObj);
                } catch (\Exception $e) {
                    $output->writeln('<error>'.$e->getMessage().'</error>');
                }
            } else {
                $output->writeln('No videos timed out.');
            }
        } else {
            $output->writeln('The option force must be set to remove owner videos timed out');
        }
    }

    private function getExpiredVideos()
    {
        $now = new \DateTime();

        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.expiration_date')->exists(true)
            ->field('properties.expiration_date')->lte($now->format('c'))
            ->getQuery()
            ->execute();
    }
}
