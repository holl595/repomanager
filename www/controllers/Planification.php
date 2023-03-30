<?php

namespace Controllers;

use Exception;

class Planification
{
    private $id;
    private $model;
    private $action;
    private $targetGpgCheck;
    private $targetGpgResign;
    private $onlySyncDifference;
    private $status;
    private $error;
    private $logfile;
    private $type;
    private $day = null;
    private $date = null;
    private $time = null;
    private $frequency = null;
    private $snapId = null;
    private $groupId = null;
    private $targetEnv = null;
    private $reminder = null;
    private $mailRecipient = null;
    private $notificationOnSuccess;
    private $notificationOnError;

    private $log;       // pour instancier un objet Log
    public $repo;       // pour instancier un objet Repo
    public $op;         // pour instancier un objet Operation
    public $group;      // pour instancier un objet Group

    private $logList = array();
    private $groupReposList;

    public function __construct()
    {

        $this->model = new \Models\Planification();
    }

    public function setId(string $id)
    {
        $this->id = $id;
    }

    public function setDay(array $days)
    {
        $planDay = '';

        /**
         *  On sépare chaque jour spécifié par une virgule
         */
        foreach ($days as $day) {
            $planDay .= \Controllers\Common::validateData($day) . ',';
        }

        /**
         *  Suppression de la dernière virgule
         */
        $this->day = rtrim($planDay, ",");
    }

    public function setDate(string $date)
    {
        $this->date = $date;
    }

    public function setTime(string $time)
    {
        $this->time = $time;
    }

    public function setType(string $type)
    {
        $this->type = $type;
    }

    public function setFrequency(string $frequency)
    {
        $this->frequency = $frequency;
    }

    public function setAction(string $action)
    {
        $this->action = $action;
    }

    public function setMailRecipient(array $mailRecipients)
    {
        foreach ($mailRecipients as $mailRecipient) {
            $mail = Common::validateData($mailRecipient);

            if (!Common::validateMail($mail)) {
                throw new Exception('Invalid email address format for ' . $mail);
            }
        }

        $this->mailRecipient = implode(',', $mailRecipients);
    }

    public function setReminder($reminders)
    {
        /**
         *  Si la planification est de type 'regular' (planification récurrente) et que la fréquence est "every-day" ou "every-hour" alors on ne set pas de rappel
         */
        if ($this->type == 'regular' and ($this->frequency == "every-day" or $this->frequency == "every-hour")) {
            return;
        }

        $planReminder = '';

        /**
         *  Si $reminders est un array
         */
        if (is_array($reminders)) {
            /**
             *  On sépare chaque jour de rappel par une virgule
             */
            foreach ($reminders as $reminder) {
                $planReminder .= \Controllers\Common::validateData($reminder) . ',';
            }
        }

        /**
         *  Suppression de la dernière virgule
         */
        $this->reminder = rtrim($planReminder, ",");
    }

    public function setNotification(string $type, string $state)
    {
        if ($type == "on-error") {
            $this->notificationOnError = $state;
        }

        if ($type == "on-success") {
            $this->notificationOnSuccess = $state;
        }
    }

    public function setTargetGpgCheck(string $state)
    {
        $this->targetGpgCheck = \Controllers\Common::validateData($state);
    }

    public function setTargetGpgResign(string $state)
    {
        $this->targetGpgResign = $state;
    }

    public function setOnlySyncDifference(string $state)
    {
        $this->onlySyncDifference = $state;
    }

    public function setTargetEnv(string $targetEnv)
    {
        $this->targetEnv = $targetEnv;
    }

    public function setSnapId(string $id)
    {
        $this->snapId = $id;
    }

    public function setGroupId(string $id)
    {
        $this->groupId = $id;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function getTime()
    {
        return $this->time;
    }

    public function getMailRecipient()
    {
        return $this->mailRecipient;
    }

    public function getMailRecipientFormatted()
    {
        /**
         *  Lorsque plusieurs adresses mail sont resneignées, on formatte le résultat retourné pour un meilleur affichage et sans virgule séparant les adresses
         *  Si une seule adresse mail est renseignée, on ne fait rien
         */
        if (preg_match('/,/', $this->mailRecipient)) {
            $mailRecipientFormatted = '';

            $mailRecipient = explode(',', $this->mailRecipient);

            foreach ($mailRecipient as $mail) {
                $mailRecipientFormatted .= $mail . '<br>';
            }

            /**
             *  Suppression du dernier saut de ligne
             */
            $mailRecipientFormatted = rtrim($mailRecipientFormatted, '<br>');

            return $mailRecipientFormatted;
        }

        return $this->mailRecipient;
    }

    /**
     *  Création d'une nouvelle planification
     */
    public function new()
    {
        /**
         *  Vérification des paramètres
         */

        /**
         *  Vérification du type
         */
        if (empty($this->type)) {
            throw new Exception("You must specify a type");
        }

        /**
         *  Vérification de la fréquence si il s'agit d'une tâche récurrente
         */
        if ($this->type == "regular" and empty($this->frequency)) {
            throw new Exception("You must specify a frequency");
        }

        /**
         *  Vérification du/des jour(s) dans le cas où il s'agit d'une planification récurrente "toutes les semaines"
         */
        if ($this->type == "regular" and $this->frequency == "every-week" and empty($this->day)) {
            throw new Exception("You must specify weekday(s)");
        }

        /**
         *  Vérification de la date (dans le cas où il s'agit d'une planification)
         */
        if ($this->type == 'plan' and empty($this->date)) {
            throw new Exception("You must specify a date");
        }

        /**
         *  Vérification de l'heure (dans le cas où il s'agit d'une planification ou d'une tâche récurrente "tous les jours" ou "toutes les semaines")
         */
        if ($this->type == 'plan' or ($this->type == 'regular' and $this->frequency == 'every-day') or ($this->type == 'regular' and $this->frequency == 'every-week')) {
            if (empty($this->time)) {
                throw new Exception("You must specify a time");
            }
        }

        /**
         *  Vérification de l'action
         */
        if (empty($this->action)) {
            throw new Exception("You must specify an action");
        }

        if ($this->action != 'update' and !preg_match('/->/', $this->action)) {
            throw new Exception("Specified action is invalid");
        }

        /**
         *  Si l'action contient un '->' on vérifie que les environnements existent
         */
        if (preg_match('/->/', $this->action)) {
            /**
             *  On récupère chacun des environnement pour vérifier si ils existent
             */
            $envs = explode('->', $this->action);

            $myenv = new \Controllers\Environment();

            foreach ($envs as $env) {
                if ($myenv->exists($env) === false) {
                    throw new Exception("Unknown environment $env");
                }
            }
        }

        /**
         *  Si aucun repo et aucun groupe n'a été renseigné alors on quitte
         */
        if (empty($this->snapId) and empty($this->groupId)) {
            throw new Exception("You must specify a repo or a group");
        }

        /**
         *  Si un repo ET un groupe ont été renseignés alors on quitte
         */
        if (!empty($this->snapId) and !empty($this->groupId)) {
            throw new Exception("You must specify a repo or a group but not both");
        }

        /**
         *  Cas où on ajoute un Id de snaphsot
         */
        if (!empty($this->snapId)) {
            /**
             *  On vérifie que l'Id de snapshot renseigné existe
             */
            $myrepo = new \Controllers\Repo();
            $myrepo->setSnapId($this->snapId);

            if ($myrepo->existsSnapId($this->snapId) === false) {
                throw new Exception("Specified repo does not exist");
            }

            unset($myrepo);
        }

        /**
         *  Cas où on ajoute un Id de groupe
         */
        if (!empty($this->groupId)) {
            /**
             *  On vérifie que l'Id de groupe renseigné existe
             */
            $mygroup = new \Controllers\Group('repo');

            if ($mygroup->existsId($this->groupId) === false) {
                throw new Exception("Specified group does not exist");
            }

            unset($mygroup);
        }

        /**
         *  Cas où on souhaite faire pointer un environnement
         */
        if (!empty($this->targetEnv)) {
            /**
             *  On vérifie que l'environnement existe
             */
            $myenv = new \Controllers\Environment();

            if ($myenv->exists($this->targetEnv) === false) {
                throw new Exception("Environment " . $this->targetEnv . " does not exist");
            }
        }

        /**
         *  On vérifie que la/les adresses renseignées sont valides
         *  Si la chaine contient une virgule alors il y a plusieurs adresses renseignées
         *  On va devoir exploser la chaine pour pouvoir tester chaque adresse mail, puis reconstruire la chaine en ne conservant que les adresses valides
         */
        if (!empty($this->mailRecipient)) {
            if (preg_match('/,/', $this->mailRecipient)) {
                $mailRecipientTest = explode(',', $this->mailRecipient);

                /**
                 *  On vérifie que chaque adresse mail est valide
                 */
                foreach ($mailRecipientTest as $mail) {
                    $mail = \Controllers\Common::validateData($mail);

                    if (\Controllers\Common::validateMail($mail) === false) {
                        throw new Exception("Invalid email address $mail");
                    }
                }

            /**
             *  Cas où 1 seule adresse mail a été renseignée
             */
            } else {
                if (\Controllers\Common::validateMail($this->mailRecipient) === false) {
                    throw new Exception("Invalid email address $mail");
                }
            }
        } else {
            /**
             *  Si aucune adresse mail de contact n'a été fournie, on passe les paramètres de notifications à 'no'
             */
            $this->setNotification('on-error', 'no');
            $this->setNotification('on-success', 'no');
        }

        /**
         *  Insertion en base de données
         */
        $this->model->add(
            $this->type,
            $this->frequency,
            $this->day,
            $this->date,
            $this->time,
            $this->action,
            $this->snapId,
            $this->groupId,
            $this->targetEnv,
            $this->targetGpgCheck,
            $this->targetGpgResign,
            $this->onlySyncDifference,
            $this->notificationOnError,
            $this->notificationOnSuccess,
            $this->mailRecipient,
            $this->reminder
        );
    }

    /**
     *  Suppression d'une planification
     */
    public function remove(string $planId)
    {
        $this->model->setStatus($planId, 'canceled');
    }

    /**
     *  Disable recurrent plan
     */
    public function suspend(string $planId)
    {
        $this->model->setStatus($planId, 'disabled');
    }

    /**
     *  Enable recurrent plan
     */
    public function enable(string $planId)
    {
        $this->model->setStatus($planId, 'queued');
    }

    /**
     *  Exécution d'une planification
     */
    public function exec()
    {
        /**
         *  On vérifie que l'Id de planification spécifié existe en base de données
         */
        if ($this->model->existsId($this->id) === false) {
            throw new Exception("Plan Id $this->id does not exist");
        }

        /**
         *  On vérifie que la planification n'est pas déjà en cours d'exécution (eg. si quelqu'un l'a lancé en avance avec le paramètre exec-now)
         */
        if ($this->model->getStatus($this->id) == 'running') {
            throw new Exception("This plan is already running");
        }

        /**
         *  On génère un nouveau log pour cette planification
         *  Ce log général reprendra tous les sous-logs de chaque opération lancée par cette planification.
         */
        $this->log = new \Controllers\Log('plan');

        /**
         *  Passe le status de la planification à "running", jusqu'à maintenant le status était "queued"
         */
        $this->model->setStatus($this->id, 'running');

        /**
         *  0. Démarre l'enregistrement de l'output de la planification
         */
        ob_start();

        try {
            // VERIFICATIONS //

            /**
             *  1. Si les planifications ne sont pas activées, on quitte
             */
            if (PLANS_ENABLED != "true") {
                throw new Exception("Plans are not enabled. You can modify this parameter from the settings page.");
            }

            /**
             *  2. On instancie des objets Operation et Group
             */
            $this->repo = new \Controllers\Repo();
            $this->group = new \Controllers\Group('repo');

            /**
             *  3. Récupération des détails de la planification en cours d'exécution, afin de savoir quels
             *  repos ou quel groupe sont impliqués et quelle action effectuer
             */
            $this->getInfo($this->id);

            /**
             *  4. Vérification de l'action renseignée
             */
            $this->checkAction();

            /**
             *  5. Si l'action est 'update' alors on vérifie que cette action est autorisée et on doit avoir renseigné gpgCheck et gpgResign
             */
            if ($this->action == "update") {
                $this->checkActionUpdateAllowed();
                $this->checkActionUpdateGpgCheck();
                $this->checkActionUpdateGpgResign();
            }

            /**
             *  6. Si l'action est '->' alors on vérifie que cette action est autorisée
             */
            // if (strpos(htmlspecialchars_decode($this->action), '->') !== false) {
            //     $this->checkActionEnvAllowed();
            // }

            /**
             *  7. Vérification si il s'agit d'un repo ou d'un groupe
             */
            $this->checkIfRepoOrGroup();

            /**
             *  8. Si on a renseigné un seul repo à traiter alors il faut vérifier qu'il existe bien (il a pu être supprimé depuis que la planification a été créée)
             *  Puis il faut récupérer son vrai nom (Redhat) ou son hôte source (Debian)
             */
            if (!empty($this->repo->getName())) {
                /**
                 *  On vérifie que l'Id de snapshot existe bien
                 */
                if ($this->repo->existsSnapId($this->repo->getSnapId()) === false) {
                    throw new Exception('Snapshot Id <b>' . $this->repo->getSnapId() . '</b> does not exist');
                }
            }

            /**
             *  9. Si on a renseigné un groupe plutôt qu'un seul repo à traiter, alors on vérifie que le groupe existe dans le fichier de groupe (il a pu être supprimé depuis que la planification a été créée)
             *  Puis on récupère toute la liste du groupe
             */
            if (!empty($this->group->getName())) {
                /**
                 *  On vérifie que le groupe existe
                 */
                if ($this->group->existsId($this->group->getId()) === false) {
                    throw new Exception('Group Id <b>' . $this->group->getId() . '</b> does not exist');
                }

                /**
                 *  On récupère la liste des repos dans ce groupe
                 */
                $this->groupReposList = $this->getGroupRepoList();
            }

        /**
         *  Clôture du try/catch de la partie Vérifications
         *  Si une erreur est catché alors on sort de la planification
         */
        } catch (\Exception $e) {
            $this->close(1, $e->getMessage());
            return;
        }

        // TRAITEMENT //

        /**
         *  On placera dans ce tableau les repos qui ont été traités par cette planification.
         */
        $processedRepos = array();

        /**
         *  1. Cas où on traite 1 repo seulement
         */
        if (!empty($this->repo->getName()) and empty($this->group->getName())) {
            /**
             *  Traitement
             *  On transmet l'ID de la planification dans $this->repo->planId, ceci afin de déclarer une nouvelle opération en BDD avec l'id de la planification qui l'a lancée
             */
            $this->repo->setPlanId($this->id);

            /**
             *  Plan does not use pool, so generate a fake pool Id
             */
            $this->repo->setPoolId('0000');

            /**
             *  Si l'action de planification == update
             */
            if ($this->action == "update") {
                /**
                 *  Set de l'environnement cible si il y en a un
                 */
                if (!empty($this->targetEnv)) {
                    $this->repo->setTargetEnv($this->targetEnv);
                }

                /**
                 *  Set target arch from the actual repo's arch
                 */
                $this->repo->setTargetArch($this->repo->getArch());

                /**
                 *  Set target Package source from the actual repo's setting (yes or no)
                 */
                $this->repo->setTargetPackageSource($this->repo->getPackageSource());

                /**
                 *  Set target Package Translation from the actual repo's setting, if there is
                 */
                if (!empty($this->repo->getPackageTranslation())) {
                    $this->repo->setTargetPackageTranslation($this->repo->getPackageTranslation());
                }

                /**
                 *  Set de GPG Check conformément à ce qui a été défini pour cette planification
                 */
                $this->repo->setTargetGpgCheck($this->targetGpgCheck);

                /**
                 *  Set de GPG Resign conformément à ce qui a été défini pour cette planification
                 */
                $this->repo->setTargetGpgResign($this->targetGpgResign);

                /**
                 *  Setting onlySyncDifference
                 */
                $this->repo->setOnlySyncDifference($this->onlySyncDifference);

                /**
                 *  Exécution de l'action 'update'
                 */
                if ($this->repo->update() === false) {
                    $status = 'error';
                } else {
                    $status = 'done';
                }

                /**
                 *  On ajoute le repo à la liste des repo traités par cette planification
                 */
                if (!empty($this->repo->getDist()) and !empty($this->repo->getSection())) {
                    $processedRepos[] = array('Repo' => $this->repo->getName() . ' ❯ ' . $this->repo->getDist() . ' ❯ ' . $this->repo->getSection(), 'Status' => $status);
                } else {
                    $processedRepos[] = array('Repo' => $this->repo->getName(), 'Status' => $status);
                }

                /**
                 *  Si l'opération est en erreur, on quitte avec un message d'erreur
                 */
                if ($status == 'error') {
                    $this->close(2, 'An error occured while processing this repo, check the logs', $processedRepos);
                    return;
                }
            }
        }

        /**
         *  2. Cas où on traite un groupe de repos
         */
        if (!empty($this->group->getName()) and !empty($this->groupReposList)) {
            /**
             *  Comme on boucle pour traiter plusieurs repos, on ne peut pas tout quitter en cas d'erreur tant qu'on a pas bouclé sur tous les repos.
             *  Du coup on initialise une variable qu'on incrémentera en cas d'erreur.
             *  A la fin si cette variable > 0 alors on pourra quitter ce script en erreur ($this->close 1)
             */
            $plan_error = 0;

            /**
             *  On placera dans ce tableau les repos qui ont été traités par cette planification.
             */
            $processedRepos = array();

            /**
             *  Traitement
             */

            /**
             *  On traite chaque ligne de groupList
             */
            foreach ($this->groupReposList as $repo) {
                /**
                 *  On (re)instancie le repo à chaque boucle afin qu'il soit bien initialisé
                 */
                $this->repo = new \Controllers\Repo();

                /**
                 *  On ne connait que l'Id du repo mais pas les informations concernant ses snapshots.
                 *  Or la fonction update() de Repo() a besoin de connaitre un Id de snapshot à mettre à jour.
                 *  On va donc lui donner l'Id du snapshot le + récent du repo
                 */
                $repoId = $repo['repoId'];

                /**
                 *  Récupération de l'Id du snapshot le + récent
                 */
                $mostRecentSnapId = $this->repo->getLastSnapshotId($repoId);

                /**
                 *  On peut récupérer toutes les informations du repo à partir de l'Id de repo et de l'Id de snapshot
                 */
                $this->repo->getAllById($repoId, $mostRecentSnapId, '');

                /**
                 *  Si le snapshot de repo est de type 'local' alors on passe au repo suivant
                 */
                if ($this->repo->getType() == 'local') {
                    continue;
                }

                /**
                 *  Traitement
                 *  On transmet l'ID de la planification dans $this->repo->planId, ceci afin de déclarer une nouvelle opération en BDD avec l'id de la planification qui l'a lancée
                 */
                $this->repo->setPlanId($this->id);

                /**
                 *  Plan does not use pool, so generate a fake pool Id
                 */
                $this->repo->setPoolId('0000');

                /**
                 *  Si $this->action() = update alors on met à jour le repo
                 */
                if ($this->action == "update") {
                    /**
                     *  Set de l'environnement cible si il y en a un
                     */
                    if (!empty($this->targetEnv)) {
                        $this->repo->setTargetEnv($this->targetEnv);
                    }

                    /**
                     *  Set target arch from the actual repo's arch
                     */
                    $this->repo->setTargetArch($this->repo->getArch());

                    /**
                     *  Set target Package source from the actual repo's setting (yes or no)
                     */
                    $this->repo->setTargetPackageSource($this->repo->getPackageSource());

                    /**
                     *  Set target Package Translation from the actual repo's setting, if there is
                     */
                    if (!empty($this->repo->getPackageTranslation())) {
                        $this->repo->setTargetPackageTranslation($this->repo->getPackageTranslation());
                    }

                    /**
                     *  Set de GPG Check conformément à ce qui a été défini pour cette planification
                     */
                    $this->repo->setTargetGpgCheck($this->targetGpgCheck);

                    /**
                     *  Set de GPG Resign conformément à ce qui a été défini pour cette planification
                     */
                    $this->repo->setTargetGpgResign($this->targetGpgResign);

                    /**
                     *  Setting onlySyncDifference
                    */
                    $this->repo->setOnlySyncDifference($this->onlySyncDifference);

                    /**
                     *  Exécution de l'action 'update'
                     */
                    if ($this->repo->update() === false) {
                        $plan_error++;
                        $status = 'error';
                    } else {
                        $status = 'done';
                    }

                    $this->logList[] = $this->repo->getOpLogLocation();

                    /**
                     *  On ajoute le repo et son status (error ou done) à la liste des repo traités par cette planifications
                     */
                    if (!empty($this->repo->getDist()) and !empty($this->repo->getSection())) {
                        $processedRepos[] = array('Repo' => $this->repo->getName() . ' ❯ ' . $this->repo->getDist() . ' ❯ ' . $this->repo->getSection(), 'Status' => $status);
                    } else {
                        $processedRepos[] = array('Repo' => $this->repo->getName(), 'Status' => $status);
                    }
                }
            }

            /**
             *  Si on a rencontré des erreurs dans la boucle, alors on quitte avec un message d'erreur
             */
            if ($plan_error > 0) {
                $this->close(2, 'An error occured while processing this group, check the logs', $processedRepos);
                return;
            }
        }

        /**
         *  Si on est arrivé jusqu'ici alors on peut quitter sans erreur
         */
        $this->close(0, '', $processedRepos);
        return;
    }

    /**
     *  Générer les messages de rappels
     *  Retourne le message approprié
     */
    public function generateReminders()
    {
        /**
         *  Vérifications
         */
        try {
            /**
             *  On instancie des objets Operation et Group
             */
            $this->repo = new \Controllers\Repo();
            $this->group = new \Controllers\Group('repo');

            /**
             *  1. Récupération des informations de la planification
             */
            $this->getInfo($this->id);

            /**
             *  2. Vérification de l'action renseignée
             */
            $this->checkAction();

            /**
             *  3. Vérification si il s'agit d'un repo ou d'un groupe
             */
            $this->checkIfRepoOrGroup();

            /**
             *  4. Si on a renseigné un seul repo à traiter alors il faut vérifier qu'il existe bien (il a pu être supprimé depuis que la planification a été créée)
             */
            if (!empty($this->repo->getName())) {
                /**
                 *  On vérifie que l'Id de snapshot existe bien
                 */
                if ($this->repo->existsSnapId($this->repo->getSnapId()) === false) {
                    throw new Exception("Snapshot Id <b>" . $this->repo->getSnapId() . "</b> does not exist");
                }
            }

            /**
             *  5. Si on a renseigné un groupe (commence par @) plutôt qu'un seul repo à traiter, alors on vérifie que le groupe existe dans le fichier de groupe (il a pu être supprimé depuis que la planification a été créée)
             *  Puis on récupère toute la liste du groupe
             */
            if (!empty($this->group->getName())) {
                /**
                 *  On vérifie que le groupe existe
                 */
                if ($this->group->existsId($this->group->getId()) === false) {
                    throw new Exception("Group Id <b>" . $this->group->getId() . "</b> does not exist");
                }

                /**
                 *  On récupère la liste des repos dans ce groupe
                 */
                $this->groupReposList = $this->getGroupRepoList();
            }

        /**
         *  Cloture du try/catch pour la partie Vérifications
         */
        } catch (\Exception $e) {
            return $e->getMessage();
        }


        // TRAITEMENT //

        /**
         *  Cas où la planif à rappeler ne concerne qu'un seul repo/section
         */
        if (!empty($this->repo->getName())) {

            /**
             *  Cas où l'action prévue est une mise à jour
             */
            if ($this->action == "update") {
                if (!empty($this->repo->getDist()) and !empty($this->repo->getSection())) {
                    return 'Update repo:<br> ➞ <span class="label-black">' . $this->repo->getName() . ' ❯ ' . $this->repo->getDist() . ' ❯ ' . $this->repo->getSection() . '</span>';
                } else {
                    return 'Update repo:<br> ➞ <span class="label-black">' . $this->repo->getName() . '</span>';
                }
            }
        }

        /**
         *  Cas où la planif à rappeler concerne un groupe de repo
         */
        if (!empty($this->group->getName()) and !empty($this->groupReposList)) {

            /**
             *  Cas où l'action prévue est une mise à jour
             */
            if ($this->action == "update") {
                $message = 'Update repos of the <span class="label-black">' . $this->group->getName() . '</span> group:<br>';
            }

            foreach ($this->groupReposList as $line) {
                /**
                 *  Pour chaque ligne on récupère les infos du repo/section
                 */
                $this->repo->setName($line['Name']);
                if (!empty($line['Dist']) and !empty($line['Section'])) {
                    $this->repo->setDist($line['Dist']);
                    $this->repo->setSection($line['Section']);
                }

                if (!empty($this->repo->getDist()) and !empty($this->repo->getSection())) {
                    $message .= ' ➞ <span class="label-black">' . $this->repo->getName() . ' ❯ ' . $this->repo->getDist() . ' ❯ ' . $this->repo->getSection() . '</span><br>';
                } else {
                    $message .= ' ➞ <span class="label-black">' . $this->repo->getName() . '</span><br>';
                }
            }

            return $message;
        }
    }

    /**
     *  Clôture d'une planification exécutée
     *  Génère le récapitulatif, le fichier de log et envoi un mail d'erreur si il y a eu une erreur.
     */
    public function close($planError, $planMessage_error, $processedRepos = null)
    {
        /**
         *  Suppression des lignes vides dans le message d'erreur si il y en a
         */
        if ($planError != 0) {
            $planMessage_error = exec("echo \"$planMessage_error\" | sed '/^$/d'");
        }

        /**
         *  Mise à jour du status de la planification en BDD
         *  On ne met à jour uniquement si le type de planification = 'plan'.
         *  Pour les planifications régulières (type 'regular') il faut les remettre en status queued.
         */
        if ($this->type == 'plan') {
            if ($planError == 0) {
                $this->model->closeUpdateStatus($this->id, 'done', null, $this->log->name);
            } else {
                $this->model->closeUpdateStatus($this->id, 'error', $planMessage_error, $this->log->name);
            }
        }
        if ($this->type == 'regular') {
            if ($planError == 0) {
                $this->model->closeUpdateStatus($this->id, 'queued', null, $this->log->name);
            } else {
                $this->model->closeUpdateStatus($this->id, 'queued', $planMessage_error, $this->log->name);
            }

            /**
             *  On met à jour l'Id du snapshot si celui-ci a changé (e.g. la planification a créé un nouveau snapshot à la date du jour)
             *  Cela permet à la planification de toujours mettre à jour le dernier snapshot en date
             *  On fait cela dans le cas d'une mise à jour sur un repo (et pas sur un groupe)
             */
            if (empty($this->group->getName())) {
                $this->model->setSnapId($this->id, $this->repo->getSnapId());
            }
        }

        /**
         *  Si l'erreur est de type 1 (erreur lors des vérifications de l'opération), on affiche les erreurs avec echo, elles seront capturées par ob_get_clean() et affichées dans le fichier de log
         *  On ajoute également les données connues de la planification, le tableau récapitulatif n'ayant pas pu être généré par l'opération puisqu'on a rencontré une erreur avant qu'elle ne se lance.
         */
        if ($planError == 1) : ?>
            <div class="div-generic-blue">
                <p class="redtext"><?= $planMessage_error ?></p><br>
                <p><b>Plan details:</b></p>
                <table>
                    <tr>
                        <td><b>Action: </b></td>
                        <td><?= $this->action ?></td>
                    </tr>
                    <?php
                    if (!empty($this->group->getName())) {
                        echo "<tr><td><b>Group: </b></td><td>" . $this->group->getName() . "</td></tr>";
                    }
                    if (!empty($this->repo->getName())) {
                        echo "<tr><td><b>Repo: </b></td><td>" . $this->repo->getName() . "</td></tr>";
                    }
                    if (!empty($this->repo->getDist())) {
                        echo "<tr><td><b>Distribution: </b></td><td>" . $this->repo->getDist() . "</td></tr>";
                    }
                    if (!empty($this->repo->getSection())) {
                        echo "<tr><td><b>Section: </b></td><td>" . $this->repo->getSection() . "</td></tr>";
                    } ?>
                </table>
            </div>
            <?php
        endif;

        // Contenu du fichier de log de la planification //

        /**
         *  Cas où on traite un groupe de repo
         *  Dans ce cas, chaque repo mis à jour crée son propre fichier de log. On a récupéré le chemin de ces fichiers de log au cours de l'opération et on l'a placé dans un array $this->logList
         *  On parcourt donc cet array pour récupérer le contenu de chaque sous-fichier de log afin de créer un fichier de log global de la planification
         */
        if (!empty($this->group->getName())) {
            if ($planError != 1) {
                /**
                 *  On laisse 3 secondes au script logbuilder.php (dans le cas où l'action est update) pour finir de forger les sous-fichiers de log, sinon on prend le risque de récupérer des contenus vide car on va trop vite
                 */
                sleep(3);
                $content = '';

                /**
                 *  Si l'array $this->logList contient des sous-fichier de log alors on récupère leur contenu en le placant dans $content
                 */
                if (!empty($this->logList)) {
                    foreach ($this->logList as $log) {
                        $content .= file_get_contents($log) . '<br><hr><br>';
                    }
                }

            /**
             *  Cas où une erreur est survenue lors des vérifications
             */
            } else {
                $content = ob_get_clean();
            }
        }

        /**
         *  Cas où on traite un seul repo
         */
        if (!empty($this->repo->getName()) and empty($this->group->getName())) {
            /**
             *  Si l'action est 'update', un sous-fichier de log sera créé par la fonction $repo->update(). Ce fichier de log existera uniquement si la fonction a pu se lancer (donc pas d'erreur lors des vérifications). On récupère donc le contenu de ce fichier uniquement si il n'y a pas eu d'erreur lors des vérifications ($planError != 1).
             */
            if ($planError != 1) {
                /**
                 *  On laisse 3 secondes au script logbuilder.php pour finir de forger le sous-fichier de log, sinon on prend le risque de récupérer un contenu vide car on va trop vite
                 */
                sleep(3);

                /**
                 *  On récupère le contenu du fichier de log généré par la fonction $repo->update() si celui-ci existe
                 *  Ce contenu sera ensuite injecté dans le fichier de log de la planification
                 */
                if (!empty($this->repo->getOpLogLocation())) {
                    $content = file_get_contents($this->repo->getOpLogLocation());
                } else {
                    $content = '';
                }

            /**
             *  Cas où une erreur est survenue lors des vérifications
             */
            } else {
                $content = ob_get_clean();
            }
        }

        /**
         *  Génération du fichier de log final à partir d'un template, le contenu précédemment récupéré est alors inclu dans le template
         */
        include(ROOT . '/templates/planification_log.inc.php');
        $this->log->write($logContent);

        /**
         *  Suppression du fichier PID
         */
        if (file_exists(PID_DIR . '/' . $this->log->getPid() . '.pid')) {
            unlink(PID_DIR . '/' . $this->log->getPid() . '.pid');
        }

        // Contenu du mail de la planification //

        /**
         *  On génère la liste du repo, ou du groupe traité
         */
        if (!empty($processedRepos)) {
            /**
             *  Ajout de l'action effectuée
             */
            $proceedReposMessage = '<br><br><b>Action:</b>';

            if ($this->action == 'update') {
                $proceedReposMessage .= ' update';
            }

            $proceedReposMessage .= '<br><br><b>Processed repos:</b><br>';

            /**
             *  On trie l'array par status des repos afin de regrouper tous les repos OK et tous les repos en erreur
             */
            array_multisort(array_column($processedRepos, 'Status'), SORT_DESC, $processedRepos);

            /**
             *  On parcourt la liste des repos traités
             */
            if (is_array($processedRepos)) {
                foreach ($processedRepos as $processedRepo) {
                    if ($processedRepo['Status'] == 'done') {
                        $proceedReposMessage .= '✅ ';
                    }
                    if ($processedRepo['Status'] == 'error') {
                        $proceedReposMessage .= '❌ ';
                    }

                    $proceedReposMessage .= '<span class="label-black">' . $processedRepo['Repo'] . '</span><br>';
                }
            }
        }

        /**
         *  Envoi d'un mail si les notifications sont activées
         */

        /**
         *  Envoi d'un mail si il n'y a pas eu d'erreurs
         */
        if (!empty($this->mailRecipient)) {
            if ($this->notificationOnSuccess == 'yes' && $planError == 0) {
                /**
                 *  Préparation du message à inclure dans le mail
                 */
                if ($this->type == 'plan') {
                    $mailSubject   = '[ OK ] - Planification #' . $this->id . ' on ' . WWW_HOSTNAME;
                    $mailPreview = 'A plan has completed.';
                }
                if ($this->type == 'regular') {
                    $mailSubject   = '[ OK ] - Regular planification #' . $this->id . ' on ' . WWW_HOSTNAME;
                    $mailPreview = 'A regular plan has completed.';
                }

                $mailMessage = 'This plan has completed successfully.' . PHP_EOL;

                /**
                 *  On ajoute le repo ou le groupe traité à la suite du message
                 */
                if (!empty($proceedReposMessage)) {
                    $mailMessage .= $proceedReposMessage . PHP_EOL;
                }

                /**
                 *  Send mail
                 */
                $mymail = new Mail($this->mailRecipient, $mailSubject, $mailMessage, 'https://' . WWW_HOSTNAME . '/run?logfile=' . $this->log->name, 'Check the details');
                $mymail->send();
            }

            /**
             *  Envoi d'un mail si il y a eu des erreurs
             */
            if ($this->notificationOnError == 'yes' && $planError != 0) {
                /**
                 *  Préparation du message à inclure dans le mail
                 */
                if ($this->type == 'plan') {
                    $mailSubject   = '[ ERROR ] - Planification #' . $this->id . ' on ' . WWW_HOSTNAME;
                    $mailPreview = 'A plan has failed.';
                }
                if ($this->type == 'regular') {
                    $mailSubject   = '[ ERROR ] - Regular planification #' . $this->id . ' on ' . WWW_HOSTNAME;
                    $mailPreview = 'A regular plan has failed.';
                }

                $mailMessage = 'This plan has encountered an error.' . PHP_EOL;

                /**
                 *  On ajoute le repo ou le groupe traité à la suite du message
                 */
                if (!empty($proceedReposMessage)) {
                    $mailMessage .= $proceedReposMessage . PHP_EOL;
                }

                /**
                 *  Send mail
                 */
                $mymail = new Mail($this->mailRecipient, $mailSubject, $mailMessage, 'https://' . WWW_HOSTNAME . '/run?logfile=' . $this->log->name, 'Check the details');
                $mymail->send();
            }
        }
    }

/**
 *  VERIFICATIONS
 *  Code d'erreurs : CP "Check Planification"
 */
    private function checkAction()
    {
        if (empty($this->action)) {
            throw new Exception("No action has been specified for this plan");
        }
    }

    private function checkActionUpdateAllowed()
    {
        /**
         *  Si la mise à jour des repos n'est pas autorisée, on quitte
         */
        if (PLANS_UPDATE_REPO != "true") {
            throw new Exception("Plans are not allowed to update repositories");
        }
    }

    private function checkActionUpdateGpgCheck()
    {
        if (empty($this->targetGpgCheck)) {
            throw new Exception("GPG check has not been specified for this plan");
        }
    }

    private function checkActionUpdateGpgResign()
    {
        if (empty($this->targetGpgResign)) {
            throw new Exception("GPG repo signature has not been specified for this plan");
        }
    }

    private function checkActionEnvAllowed()
    {
        /**
         *  Si le changement d'environnement n'est pas autorisé, on quitte
         */
        if (PLANS_UPDATE_REPO_ENV != "true") {
            throw new Exception("Plans are not allowed to manage repos environment");
        }
    }

    /**
     *  Vérification si on traite un repo seul ou un groupe
     */
    private function checkIfRepoOrGroup()
    {
        if (empty($this->repo->getName()) and empty($this->group->getName())) {
            throw new Exception("No repo or group has been specified");
        }

        /**
         *  On va traiter soit un repo soit un groupe de repo, ça ne peut pas être les deux, donc on vérifie que planRepo et planGroup ne sont pas tous les deux renseignés en même temps :
         */
        if (!empty($this->repo->getName()) and !empty($this->group->getName())) {
            throw new Exception("It is not possible to process both a repo and a group");
        }
    }

    /**
     *  Récupération de la liste des repo dans le groupe
     */
    private function getGroupRepoList()
    {
        /**
         *  On récupère tous les repos membres du groupe
         */
        $repos = $this->repo->listNameByGroup($this->group->getName());

        if (empty($repos)) {
            throw new Exception("No repo has been found in group <b>" . $this->group->getName() . "</b>");
        }

        return $repos;
    }

    /**
     *  Retourne la liste des planifications en attente d'exécution
     */
    public function listQueue()
    {
        return $this->model->getQueue();
    }

    /**
     *  Liste les planifications en cours d'exécution
     */
    public function listRunning()
    {
        return $this->model->listRunning();
    }

    /**
     *  List disabled recurrent plan
     */
    public function listDisabled()
    {
        return $this->model->listDisabled();
    }

    /**
    *  Liste les planifications terminées (tout status compris sauf canceled)
    */
    public function listDone()
    {
        return $this->model->listDone();
    }

    /**
     *  Liste la dernière planification exécutée
     */
    public function listLast()
    {
        return $this->model->listLast();
    }

    /**
     *  Liste la prochaine planification qui sera exécutée
     */
    public function listNext()
    {
        return $this->model->listNext();
    }

    /**
    *   Récupère toutes les infos d'une planification
    *   Un objet Operation doit avoir été instancié pour récupérer les infos concernant le repo concerné par cette planification
    */
    private function getInfo(string $id)
    {
        $planInfo = $this->model->getInfo($id);

        /**
         *  On défini les propriété de la planification à partir des infos récupérées
         */

        /**
         *  La date et l'heure sont utiles à récupérer pour la génération des rappels
         */
        if (!empty($planInfo['Date'])) {
            $this->setDate($planInfo['Date']);
        }
        if (!empty($planInfo['Time'])) {
            $this->setTime($planInfo['Time']);
        }

        /**
         *  Type de planification
         */
        $this->setType($planInfo['Type']);

        /**
         *  Fréquence d'exécution (si type 'regular')
         */
        if (!empty($planInfo['Frequency'])) {
            $this->frequency = $planInfo['Frequency'];
        }

        /**
         *  Action
         */
        $this->setAction($planInfo['Action']);

        /**
         *  Id du repo ou du groupe
         */
        if (!empty($planInfo['Id_snap'])) {
            $this->repo->setSnapId($planInfo['Id_snap']);
            $this->repo->getAllById('', $this->repo->getSnapId(), '');
        }
        if (!empty($planInfo['Id_group'])) {
            $this->group->setId($planInfo['Id_group']);
            $groupName = $this->group->getNameById($planInfo['Id_group']);
            $this->group->setName($groupName);
        }

        /**
         *  Environnement cible si il y en a un
         */
        if (!empty($planInfo['Target_env'])) {
            $this->setTargetEnv($planInfo['Target_env']);
        }

        /**
         *  Les paramètres GPG Check et GPG Resign sont conservées de côté et seront pris en compte au début de l'exécution de update()
         */
        if (!empty($planInfo['Gpgcheck'])) {
            $this->setTargetGpgCheck($planInfo['Gpgcheck']);
        }
        if (!empty($planInfo['Gpgresign'])) {
            $this->setTargetGpgResign($planInfo['Gpgresign']);
        }

        if (!empty($planInfo['OnlySyncDifference'])) {
            $this->onlySyncDifference = $planInfo['OnlySyncDifference'];
        }

        /**
         *  Rappels de planification
         */
        $this->setReminder($planInfo['Reminder']);

        /**
         *  Notifications par mail si erreur ou si terminé
         */
        $this->setNotification('on-error', $planInfo['Notification_error']);
        $this->setNotification('on-success', $planInfo['Notification_success']);

        /**
         *  Adresse mail de destination
         */
        if (!empty($planInfo['Mail_recipient'])) {
            $this->setMailRecipient(explode(',', $planInfo['Mail_recipient']));
        }
    }

    /**
     *  Passe le status d'une planification en stopped
     */
    public function stopPlan(string $planId)
    {
        $this->model->setStatus($planId, 'stopped');
    }
}
