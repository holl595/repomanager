<?php
global $WWW_DIR;
require_once("${WWW_DIR}/class/Database.php");
require_once("${WWW_DIR}/class/Log.php");
require_once("${WWW_DIR}/class/Repo.php");
require_once("${WWW_DIR}/class/Group.php");
require_once("${WWW_DIR}/functions/common-functions.php"); // pour avoir accès à la fonction clearCache
include_once("${WWW_DIR}/class/includes/new.php");
include_once("${WWW_DIR}/class/includes/update.php");
include_once("${WWW_DIR}/class/includes/op_printDetails.php");
include_once("${WWW_DIR}/class/includes/op_getPackages.php");
include_once("${WWW_DIR}/class/includes/op_signPackages.php");
include_once("${WWW_DIR}/class/includes/op_createRepo.php");
include_once("${WWW_DIR}/class/includes/op_archive.php");
include_once("${WWW_DIR}/class/includes/op_finalize.php");
include_once("${WWW_DIR}/class/includes/newLocalRepo.php");
include_once("${WWW_DIR}/class/includes/changeEnv.php");
include_once("${WWW_DIR}/class/includes/delete.php");
include_once("${WWW_DIR}/class/includes/deleteDist.php");
include_once("${WWW_DIR}/class/includes/deleteSection.php");
include_once("${WWW_DIR}/class/includes/duplicate.php");
include_once("${WWW_DIR}/class/includes/deleteArchive.php");
include_once("${WWW_DIR}/class/includes/restore.php");
include_once("${WWW_DIR}/class/includes/cleanArchives.php");
include_once("${WWW_DIR}/class/includes/reconstruct.php");

class Operation {
    public $db;
    public $action; // Doit rester public car peut être modifié par operation.php
    public $status; // Doit rester public car peut être modifié par Planification.php (exec())
    private $id;    // Id de l'opération en BDD
    private $type;
    private $date;
    private $time;
    
    private $validate = 0;   
    private $timeStart = "";
    private $timeEnd = "";

    public $repo;    // pour instancier un objet Repo
    public $log;     // pour instancier un objet Log
    public $id_plan; // Si une opération est lancée par une planification alors on peut stocker l'ID de cette planification dans cette variable

    /**
     *  Import des traits nécessaires pour les opérations sur les repos/sections
     */
    use newMirror, newLocalRepo, update, changeEnv, duplicate, delete, deleteDist, deleteSection, deleteArchive, restore, cleanArchives;
    use op_printDetails, op_getPackages, op_signPackages, op_createRepo, op_archive, op_finalize;
    use reconstruct;

    public function __construct(array $variables = []) {
        extract($variables);

        /**
         *  Instanciation d'une db car on peut avoir besoin de récupérer certaines infos en BDD
         */
        try {
            $this->db = new Database();
        } catch(Exception $e) {
            die('Erreur : '.$e->getMessage());
        }

        if (!empty($op_action)) {
            $this->action = $op_action;
        } /*else {
            throw new Error("Erreur : aucune action n'a été renseignée");
        }*/

        if (!empty($op_type) AND $op_type == "plan") {
            $this->type = 'plan';
        } else {
            $this->type = 'manual';
        }

        //$this->status = 'pending';
        $this->repo = new Repo();
    }

    /**
     *  Lister les opérations en cours d'exécution en fonction du type souhaité (opérations manuelles ou planifiées)
     */
    public function listRunning(string $type = '') {
        /**
         *  Si le type est laissé vide alors on affiche tous les types d'opérations. Sinon on affiche les opérations selon le type souhaité (manuelles ou planifiées)
         */
        if (!empty($type) AND $type != 'manual' AND $type != 'plan') {
            throw new Error("Type d'opération non reconnu");
        }

        /**
         *  Cas où on souhaite tous les types
         */
        if (empty($type)) {
            $stmt = $this->db->prepare("SELECT * FROM operations WHERE Status = 'running'");
            $result = $stmt->execute();

        /**
         *  Cas où souhaite filtrer par un type en particulier
         */
        } else {
            $stmt = $this->db->prepare("SELECT * FROM operations WHERE Status = 'running' AND Type=:type");
            $stmt->bindValue(':type', $type);
            $result = $stmt->execute();
        }

        while ($datas = $result->fetchArray()) { $operations[] = $datas; }
        if (!empty($operations)) {
            return $operations;
        } else {
            return false;
        }
    }

    /**
     *  Lister les opérations terminées (avec ou sans erreurs)
     */
    public function listDone(string $type = '') {
        /**
         *  Si le type est laissé vide alors on affiche tous les types d'opérations. Sinon on affiche les opérations selon le type souhaité (manuelles ou planifiées)
         */
        if (!empty($type) AND $type != 'manual' AND $type != 'plan') {
            throw new Error("Type d'opération non reconnu");
        }

        /**
         *  Cas où on souhaite tous les types
         */
        if (empty($type)) {
            $stmt = $this->db->prepare("SELECT * FROM operations WHERE Status = 'error' OR Status = 'done' OR Status = 'stopped' ORDER BY Date DESC, Time DESC");
            $result = $stmt->execute();
        /**
         *  Cas où souhaite filtrer par un type en particulier
         */
        } else {
            $stmt = $this->db->prepare("SELECT * FROM operations WHERE Status = 'error' OR Status = 'done' OR Status = 'stopped' AND Type=:type ORDER BY Date DESC, Time DESC");
            $stmt->bindValue(':type', $type);
            $result = $stmt->execute();
        }

        while ($datas = $result->fetchArray()) { $operations[] = $datas; }
        if (!empty($operations)) {
            return $operations;
        } else {
            return false;
        }
    }

    public function kill($pid) {
        global $PID_DIR;

        if (file_exists("${PID_DIR}/${pid}.pid")) {

            /**
             * 	Récupération du nom de fichier de log car on va avoir besoin d'indiquer dedans que l'opération a été stoppée
             */
            $logFile = exec("grep '^LOG=' ${PID_DIR}/${pid}.pid | sed 's/LOG=//g' | sed 's/\"//g'");
    
            /**
             * 	Récupération des subpid car il va falloir les tuer aussi
             */
            $subpids = shell_exec("grep -h '^SUBPID=' ${PID_DIR}/${pid}.pid | sed 's/SUBPID=//g' | sed 's/\"//g'");
            
            /**
             * 	Kill des subpids si il y en a
             */
            if (!empty($subpids)) {
                $subpids = explode("\n", trim($subpids));
                foreach($subpids as $subpid) {
                    exec("kill -9 $subpid");
                }
            }
    
            /**
             * 	Suppression du fichier pid principal
             */
            unlink("${PID_DIR}/${pid}.pid");
        }

        /*  if (!empty($logFile)) {
            file_put_contents("$MAIN_LOGS_DIR/$logFile", '<p>Opération stoppée par l\'utilisateur</p>'.PHP_EOL, FILE_APPEND);
        }*/

        /**
         *  Si cette opération a été lancée par un planification, il faudra mettre à jour la planification en BDD
         *  On récupère d'abord l'ID de la planification
         */
        $stmt = $this->db->prepare("SELECT Id_plan FROM operations WHERE Pid=:pid AND Status = 'running'");
        $stmt->bindValue(':pid', $pid);
        $result = $stmt->execute();
        while ($datas = $result->fetchArray()) { $planId = $datas['Id_plan']; }

        /**
         *  Mise à jour de l'opération en BDD, on la passe en status = stopped
         */
        $stmt = $this->db->prepare("UPDATE operations SET Status = 'stopped' WHERE Pid=:pid AND Status = 'running'");
        $stmt->bindValue(':pid', $pid);
        $stmt->execute();

        /**
         *  Mise à jour de la planification en BDD
         */
        if (!empty($planId)) {
            $stmt = $this->db->prepare("UPDATE planifications SET Status = 'stopped' WHERE Id=:id AND Status = 'running'");
            $stmt->bindValue(':id', $planId);
            $stmt->execute();
            unset($planId);
        }

        unset($stmt, $datas, $result);
        printAlert("L'opération a été arrêtée");

        clearCache();
    }

    private function validate() {
        if ($this->validate > 0) {
            echo '<button type="submit" class="button-submit-large-red">Valider</button>';
            return false;
        } else {
            return true;
        }
    }

    private function confirm() {
        if (empty($_GET['confirm'])) {
            echo '<button type="submit" id="confirmButton" class="button-submit-large-red" name="confirm" value="yes">Confirmer et exécuter</button>';
            return false;
        }
        return true;
    }

    /**
     *  Vérifie que l'ID passé correspond bien à un repo en BDD
     */
    private function chk_param_id() {
        if (empty($_GET['repoId'])) {
            echo "<p>Erreur : l'ID du repo ne peut pas être vide.</p>";
            return false;
        }

        /**
         *  Récupération de l'ID en GET
         */
        $this->repo->id = validateData($_GET['repoId']);

        if (!is_numeric($this->repo->id)) {
            echo "<p>Erreur : l'ID du repo doit être un nombre.</p>";
            return false;
        }

        /**
         *  On a besoin de connaitre l'état du repo pour l'étape suivante, celui-ci a normalement été transmis en GET par state=
         */
        if (empty($_GET(['state']))) {
            echo "<p>Erreur : l'état du repo n'est pas renseigné.</p>";
            return false;
        }
        if (validateData($_GET(['state'])) != "active" AND validateData($_GET(['state']) != "archived")) {
            echo "<p>Erreur : l'état du repo est invalide.</p>";
            return false;
        }

        /**
         *  On vérifie que l'ID spécifié existe en BDD
         */
        if ($this->repo->existsId(validateData($_GET(['state']))) === false) return false;

        return true;
    }

    private function chk_param_source() {
        if (empty($_GET['repoSource'])) {
            echo "<p>Erreur : la source ne peut pas être vide</p>";
            return false;
        } else {
            $this->repo->source = validateData($_GET['repoSource']);
            if (!is_alphanum($this->repo->source, array('-', '.'))) { // on autorise les points dans le nom de la source
                echo '<p>Erreur : la source du repo ne peut pas contenir de caractères spéciaux hormis le tiret -</p>';
                return false;
            }

            echo '<input type="hidden" name="repoSource" value="'.$this->repo->source.'" />';
        }
        return true;
    }

    private function chk_param_type() {
        if (empty($_GET['repoType'])) {
            echo "<p>Erreur : le type de repo ne peut pas être vide</p>";
            return false;
        } else {
            $this->repo->type = validateData($_GET['repoType']);
            if (!is_alphanum($this->repo->type)) {
                echo '<p>Erreur : le type du repo ne peut pas contenir de caractères spéciaux.</p>';
                return false;
            }

            echo '<input type="hidden" name="repoType" value="'.$this->repo->type.'" />';
        }
        return true;
    }

    private function chk_param_alias() {
        if (empty($_GET['repoAlias'])) {
            $this->repo->alias = "noalias";
            echo '<input type="hidden" name="repoAlias" value="noalias" />';
        } else {
            $this->repo->alias = validateData($_GET['repoAlias']);
            if (!is_alphanum($this->repo->alias, array('-'))) {
                echo '<p>Erreur : le nom du repo ne peut pas contenir de caractères spéciaux hormis le tiret -</p>';
                return false;
            }

            echo '<input type="hidden" name="repoAlias" value="'.$this->repo->alias.'" />';
        }
        return true;
    }

    private function chk_param_name() {
        if (empty($_GET['repoName'])) {
            echo '<p>Erreur : le nom du repo ne peut pas être vide</p>';
            return false;
        } else {
            $this->repo->name = validateData($_GET['repoName']);
            if (!is_alphanum($this->repo->name, array('-'))) {
                echo '<p>Erreur : le nom du repo ne peut pas contenir de caractères spéciaux hormis le tiret -</p>';
                return false;
            }

            echo '<input type="hidden" name="repoName" value="'.$this->repo->name.'" />';
        }
        return true;
    }

    private function chk_param_newName() {
        if (empty($_GET['repoNewName'])) {
            echo '<span>Nouveau nom du repo :</span><input type="text" name="repoNewName" required />';
            return false;
        } else {
            $this->repo->newName = validateData($_GET['repoNewName']);
            if (!is_alphanum($this->repo->newName, array('-'))) {
                echo '<p>Erreur : le nom du repo ne peut pas contenir de caractères spéciaux hormis le tiret -</p>';
                return false;
            }

            echo '<input type="hidden" name="repoNewName" value="'.$this->repo->newName.'" />';
        }
        return true;
    }

    private function chk_param_dist() {
        if (empty($_GET['repoDist'])) {
            echo "<p>Erreur : le nom de la distribution ne peut pas être vide</p>";
            return false;
        } else {
            $this->repo->dist = validateData($_GET['repoDist']);
            if (!is_alphanum($this->repo->dist, array('-'))) {
                echo '<p>Erreur : le nom de la distribution ne peut pas contenir de caractères spéciaux hormis le tiret -</p>';
                return false;
            }
            
            echo '<input type="hidden" name="repoDist" value="'.$this->repo->dist.'" />';
        }
        return true;
    }

    private function chk_param_section() {
        if (empty($_GET['repoSection'])) {
            echo "<p>Erreur : le nom de la section ne peut pas être vide</p>";
            return false;
        } else {
            $this->repo->section = validateData($_GET['repoSection']);
            if (!is_alphanum($this->repo->section, array('-'))) {
                echo '<p>Erreur : le nom de la section ne peut pas contenir de caractères spéciaux hormis le tiret -</p>';
                return false;
            }

            echo '<input type="hidden" name="repoSection" value="'.$this->repo->section.'" />';
        }
        return true;
    }

    private function chk_param_gpgCheck() {
        if (empty($_GET['repoGpgCheck'])) {
            echo '<td><input type="hidden" name="repoGpgCheck" value="no"></td>';
        }
        if (!empty($_GET['repoGpgCheck'])) {
            if (validateData($_GET['repoGpgCheck']) === "ask") {
                echo '<span class="op_span">GPG check</span>';
                echo '<label class="onoff-switch-label">';
                echo '<input name="repoGpgCheck" type="checkbox" class="onoff-switch-input" value="yes" checked />';
                echo '<span class="onoff-switch-slider"></span>';
                echo '</label><br>';
                return false;
            } else {
                $this->repo->gpgCheck = validateData($_GET['repoGpgCheck']);
                echo '<input type="hidden" name="repoGpgCheck" value="'.$this->repo->gpgCheck.'" />';
            }
        }
        return true;
    }

    private function chk_param_gpgResign() {
        global $GPG_SIGN_PACKAGES;

        if (empty($_GET['repoGpgResign'])) {
            echo '<td><input type="hidden" name="repoGpgResign" value="no"></td>';
        }
        if (!empty($_GET['repoGpgResign'])) {
            if (validateData($_GET['repoGpgResign']) === "ask") {
                echo '<span class="op_span">Signer avec GPG</span>';
                echo '<label class="onoff-switch-label">';
                echo '<input name="repoGpgResign" type="checkbox" class="onoff-switch-input" value="yes"'; if ($GPG_SIGN_PACKAGES == "yes") { echo 'checked'; } echo ' />';
                echo '<span class="onoff-switch-slider"></span>';
                echo '</label><br>';
                return false;

            } else {
                $this->repo->gpgResign = validateData($_GET['repoGpgResign']);
                echo '<input type="hidden" name="repoGpgResign" value="'.$this->repo->gpgResign.'" />';
            }
        }
        return true;
    }

    /**
     *  Le groupe peut être vide
     */
    private function chk_param_group() {
        if (empty($_GET['repoGroup'])) {
            echo '<input type="hidden" name="repoGroup" value="nogroup">';
        }
        if (!empty($_GET['repoGroup'])) {
            if (validateData($_GET['repoGroup']) === "ask") {
                $group = new Group();
                $groupList = $group->listAllName();
                // on va afficher le tableau de groupe seulement si la commande précédente a trouvé des groupes (résultat non vide) :
                if (!empty($groupList)) {
                    echo '<span>Ajouter à un groupe (fac.)</span>';
                    echo '<select name="repoGroup">';
                    echo '<option value="">Sélectionner un groupe...</option>';
                    foreach($groupList as $groupName) {
                        echo "<option value=\"$groupName\">$groupName</option>";
                    }
                    echo '</select>';

                } else { // Si on n'a aucun groupe sur ce serveur, alors aucune liste ne s'affichera. Dans ce cas on définit repoGroup à 'nogroup'
                    echo '<input type="hidden" name="repoGroup" value="nogroup">';
                }

            } else {
                $this->repo->group = validateData($_GET['repoGroup']);
                echo '<input type="hidden" name="repoGroup" value="'.$this->repo->group.'" />';
            }
        }
        return true;
    }

    /**
     *  La description peut être vide
     */
    private function chk_param_description() {
        if (empty($_GET['repoDescription'])) {
            echo '<input type="hidden" name="repoDescription" value="nodescription" />';
        } else {
            if (validateData($_GET['repoDescription']) === "ask") {
                echo '<span>Description (fac.) :</span><input type="text" name="repoDescription" />';
            } else {
                $this->repo->description = validateData($_GET['repoDescription']);
                if (!is_alphanumdash($this->repo->description, array('.', '(', ')', '@', '+', '\'', ' '))) { // on accepte certains caractères spéciaux dans la description.
                    echo '<p>Erreur : la description comporte des caractères invalides</p>';
                    return false;
                }

                echo '<input type="hidden" name="repoDescription" value="'.$this->repo->description.'" />';
            }
        }
        return true;
    }

    private function chk_param_env() {
        global $ENVS;

        if (empty($_GET['repoEnv'])) {
            echo "<p>Erreur : le nom de l'environnement ne peut pas être vide</p>";
            return false;
        } else {
            if (validateData($_GET['repoEnv']) === "ask") {
                echo '<span>Env. actuel :</span>';
                echo '<select name="repoEnv" required>';
                foreach($ENVS as $env) {
                    echo "<option value=\"${env}\">${env}</option>";
                }
                echo '</select>';
                return false;
            } else {
                $this->repo->env = validateData($_GET['repoEnv']);
                echo '<input type="hidden" name="repoEnv" value="'.$this->repo->env.'" />';
            }
        }
        return true;
    }

    private function chk_param_newEnv() {
        global $ENVS;

        if (empty($_GET['repoNewEnv'])) {
            echo "<p>Erreur : le nouveau nom de l'environnement ne peut pas être vide</p>";
            return false;
        } else {
            if (validateData($_GET['repoNewEnv']) === "ask") {
                echo '<span>Env cible :</span>';
                echo '<select name="repoNewEnv" required>';
                foreach($ENVS as $env) {
                    if ($env !== $this->repo->env) { // on ne réaffiche pas l'env en cours
                        echo "<option value=\"${env}\">${env}</option>";
                    }
                }
                echo '</select>';
                return false;
            } else {
                $this->repo->newEnv = validateData($_GET['repoNewEnv']);
                echo '<input type="hidden" name="repoNewEnv" value="'.$this->repo->newEnv.'" />';
            }
        }
        return true;
    }

    private function chk_param_date() {
        if (empty($_GET['repoDate'])) {
            echo "<p>Erreur : la date ne peut pas être vide</p>";
            return false;
        } else {
            $this->repo->date = validateData($_GET['repoDate']);
            $this->repo->dateFormatted = DateTime::createFromFormat('Y-m-d', $this->repo->date)->format('d-m-Y');
            echo '<input type="hidden" name="repoDate" value="'.$this->repo->date.'" />';
        }
        return true;
    }

    /**
     *  NOUVELLE OPERATION
     *  Ajout d'une nouvelle entrée en BDD
     */
    public function startOperation(array $variables = []) {
        extract($variables);

        $this->date = date("Y-m-d");
        $this->time = date("H:i:s");
        $this->timeStart = microtime(true); // timeStart sera destiné à calculer le temps écoulé pour l'opération.
        $this->status = 'running';
        $this->log = new Log('repomanager');

        $stmt = $this->db->prepare("INSERT INTO operations (date, time, action, type, pid, logfile, status) VALUES (:date, :time, :action, :type, :pid, :logfile, :status)");
        $stmt->bindValue(':date', $this->date);
        $stmt->bindValue(':time', $this->time);
        $stmt->bindValue(':action', $this->action);
        $stmt->bindValue(':type', $this->type);
        $stmt->bindValue(':pid', $this->log->pid);
        $stmt->bindValue(':logfile', $this->log->name);
        $stmt->bindValue(':status', $this->status);
        $stmt->execute();

        unset($stmt);

        // Récupération de l'ID de l'opération précédemment créée en BDD car on en aura besoin pour clore l'opération
        $this->id = $this->db->lastInsertRowID();

        /**
         *  Si un ID de planification a été renseigné en appelant startOperation alors on l'ajoute directement en BDD
         */
        if (!empty($id_plan)) {
            $this->db_update_idplan($id_plan);
            unset($id_plan);
        }

        /**
         *  Si un ID de repo source a été renseigné en appelant startOperation alors on l'ajoute directement en BDD
         */
        if (!empty($id_repo_source)) {
            $this->db_update_idrepo_source($id_repo_source);
            unset($id_repo_source);
        }

        /**
         *  Si un ID de repo cible a été renseigné en appelant startOperation alors on l'ajoute directement en BDD
         */
        if (!empty($id_repo_target)) {
            $this->db_update_idrepo_target($id_repo_target);
            unset($id_repo_target);
        }

        /**
         *  Si un ID de groupe a été renseigné en appelant startOperation alors on l'ajoute directement en BDD
         */
        if (!empty($id_group)) {
            $this->db_update_idgroup($id_group);
            unset($id_group);
        }

        /**
         *  Si gpgCheck a été renseigné en appelant startOperation alors on l'ajoute directement en BDD
         */
        if (!empty($gpgCheck)) {
            $this->db_update_gpgCheck($gpgCheck);
            unset($gpgCheck);
        }

        /**
         *  Si gpgResign a été renseigné en appelant startOperation alors on l'ajoute directement en BDD
         */
        if (!empty($gpgResign)) {
            $this->db_update_gpgResign($gpgResign);
            unset($gpgResign);
        }
    }

    public function db_update_idplan($id_plan) {
        $stmt = $this->db->prepare("UPDATE operations SET Id_plan=:id_plan WHERE Id=:id");
        $stmt->bindValue(':id_plan', $id_plan);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();
        unset($stmt);
    }

    public function db_update_idrepo_source($id_repo_source) {
        $stmt = $this->db->prepare("UPDATE operations SET Id_repo_source=:id_repo_source WHERE Id=:id");
        $stmt->bindValue(':id_repo_source', $id_repo_source);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();
        unset($stmt);
    }

    public function db_update_idrepo_target($id_repo_target) {
        $stmt = $this->db->prepare("UPDATE operations SET Id_repo_target=:id_repo_target WHERE Id=:id");
        $stmt->bindValue(':id_repo_target', $id_repo_target);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();
        unset($stmt);
    }

    public function db_update_idgroup($id_group) {
        $stmt = $this->db->prepare("UPDATE operations SET Id_group=:id_group WHERE Id=:id");
        $stmt->bindValue(':id_group', $id_group);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();
        unset($stmt);
    }

    public function db_update_gpgCheck($gpgCheck) {
        $stmt = $this->db->prepare("UPDATE operations SET GpgCheck=:gpgCheck WHERE Id=:id");
        $stmt->bindValue(':gpgCheck', $gpgCheck);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();
        unset($stmt);
    }

    public function db_update_gpgResign($gpgResign) {
        $stmt = $this->db->prepare("UPDATE operations SET GpgResign=:gpgResign WHERE Id=:id");
        $stmt->bindValue(':gpgResign', $gpgResign);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();
        unset($stmt);
    }


    /**
     *  CLOTURE D'UNE OPERATION
     *  Modifie le status en BDD
     */
    public function closeOperation() {
        $this->timeEnd = microtime(true);
        $this->duration = $this->timeEnd - $this->timeStart; // $this->duration = nombre de secondes totales pour l'exécution de l'opération

        $stmt = $this->db->prepare("UPDATE operations SET Status=:status, Duration=:duration WHERE Id=:id");
        $stmt->bindValue(':status', $this->status);
        $stmt->bindValue(':duration', $this->duration);
        $stmt->bindValue(':id', $this->id);
        $stmt->execute();

        // Cloture du fichier de log ouvert par startOperation()
        $this->log->close();

        unset($stmt);

        clearCache();
    }

    /**
     *  CREER UN NOUVEAU REPO/SECTION
     */
    public function new() {
        global $OS_FAMILY;
        global $WWW_DIR;
        global $WWW_HOSTNAME;
        global $REPOMANAGER_YUM_DIR;
        global $DEFAULT_ENV;

        if ($OS_FAMILY === "Redhat") echo '<h3>CRÉER UN NOUVEAU REPO</h3>';
        if ($OS_FAMILY === "Debian") echo '<h3>CRÉER UNE NOUVELLE SECTION</h3>';
        if ($this->chk_param_type() === false) ++$this->validate;
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_dist() === false)    ++$this->validate; }
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_section() === false) ++$this->validate; }

        if ($this->chk_param_alias() === false) ++$this->validate;
        $this->chk_param_group();
        if ($this->chk_param_description() === false) ++$this->validate;
        if ($this->repo->type === "mirror") {
            if ($this->chk_param_source() === false)    ++$this->validate; 
            if ($this->chk_param_gpgCheck() === false)  ++$this->validate;
            if ($this->chk_param_gpgResign() === false) ++$this->validate;
        }

        if ($this->repo->alias === "noalias") {
            $this->repo->name = $this->repo->source;
        } else {
            $this->repo->name = $this->repo->alias;
        }
        $this->repo->env = $DEFAULT_ENV;

        if ($this->validate() === true) {
            /**
             *  Ok on a toutes les infos mais il faut vérifier qu'un/une repo/section du même nom n'existe pas déjà
             */
            if ($OS_FAMILY == "Redhat" AND $this->repo->exists($this->repo->name) === true) {
                echo "<p>Erreur : Un repo du même nom existe déjà en ".envtag($this->repo->env)."</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            } 
            if ($OS_FAMILY == "Debian" AND $this->repo->section_exists($this->repo->name, $this->repo->dist, $this->repo->section) === true) {
                echo "<p>Erreur : Une section du même nom existe déjà en ".envtag($this->repo->env)."</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  On vérifie que le repo source existe dans /etc/yum.repos.d/repomanager/ (uniquement dans le cas d'un miroir)
             */
            if ($this->repo->type == 'mirror') {
                if ($OS_FAMILY == "Redhat") {
                    $checkifRepoRealnameExist = exec("grep '^\\[{$this->repo->source}\\]' ${REPOMANAGER_YUM_DIR}/*.repo");
                    if (empty($checkifRepoRealnameExist)) {
                        echo "<p>Erreur : Il n'existe aucun repo source pour le nom de repo [{$this->repo->source}]</p>";
                        echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                        return false;
                    }
                }
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if ($this->repo->type == 'mirror') {
                if (empty($_GET['confirm'])) {
                    if ($OS_FAMILY == "Redhat") echo '<p>L\'opération va créer un nouveau repo :</p>';
                    if ($OS_FAMILY == "Debian") echo '<p>L\'opération va créer une nouvelle section :</p>';
                    if ($OS_FAMILY == "Redhat") echo "<span>Nom du repo :</span><span><b>{$this->repo->name} ".envtag($this->repo->env)." ({$this->repo->source})</b></span>";
                    if ($OS_FAMILY == "Debian") { 
                        echo "<span>Nom du repo :</span><span><b>{$this->repo->name}</b> ({$this->repo->source})</span>";
                        echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                        echo "<span>Section :</span><span><b>{$this->repo->section}</b> ".envtag($this->repo->env)."</span>";
                    }
                }

                echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

                if ($this->confirm() === true) {
                    /*if ($OS_FAMILY == "Redhat") exec("php ${WWW_DIR}/operations/newMirror.php '{$this->repo->name}' '{$this->repo->source}' '{$this->repo->gpgCheck}' '{$this->repo->gpgResign}' '{$this->repo->group}' '{$this->repo->description}' '{$this->repo->type}' >/dev/null 2>/dev/null &");
                    if ($OS_FAMILY == "Debian") exec("php ${WWW_DIR}/operations/newMirror.php '{$this->repo->name}' '{$this->repo->dist}' '{$this->repo->section}' '{$this->repo->source}' '{$this->repo->gpgCheck}' '{$this->repo->gpgResign}' '{$this->repo->group}' '{$this->repo->description}' '{$this->repo->type}' >/dev/null 2>/dev/null &");*/
                    if ($OS_FAMILY == "Redhat") exec("php ${WWW_DIR}/operations/execute.php --action='new' --name='{$this->repo->name}' --source='{$this->repo->source}' --gpgCheck='{$this->repo->gpgCheck}' --gpgResign='{$this->repo->gpgResign}' --group='{$this->repo->group}' --description='{$this->repo->description}' --type='mirror' >/dev/null 2>/dev/null &");
                    if ($OS_FAMILY == "Debian") exec("php ${WWW_DIR}/operations/execute.php --action='new' --name='{$this->repo->name}' --dist='{$this->repo->dist}' --section='{$this->repo->section}' --source='{$this->repo->source}' --gpgCheck='{$this->repo->gpgCheck}' --gpgResign='{$this->repo->gpgResign}' --group='{$this->repo->group}' --description='{$this->repo->description}' --type='mirror' >/dev/null 2>/dev/null &");                    
                    echo "<script>window.location.replace('/run.php');</script>"; // On redirige vers la page de logs pour voir l'exécution
                }
            }

            if ($this->repo->type == 'local') {
                if (empty($_GET['confirm'])) {
                    if ($OS_FAMILY == "Redhat") echo '<p>L\'opération va créer un nouveau repo local :</p>';
                    if ($OS_FAMILY == "Debian") echo '<p>L\'opération va créer une nouvelle section de repo local :</p>';
                    if ($OS_FAMILY == "Redhat") echo "<span>Nom du repo :</span><span><b>{$this->repo->name}</b> ".envtag($this->repo->env)."</span>";
                    if ($OS_FAMILY == "Debian") { 
                        echo "<span>Nom du repo :</span><span><b>{$this->repo->name}</b></span>";
                        echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                        echo "<span>Section :</span><span><b>{$this->repo->section}</b> ".envtag($this->repo->env)."</span>";
                    }
                }

                echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

                if ($this->confirm() === true) {
                    if ($OS_FAMILY == "Redhat") $this->startOperation(array('id_repo_target' => "{$this->repo->name}"));
                    if ($OS_FAMILY == "Debian") $this->startOperation(array('id_repo_target' => "{$this->repo->name}|{$this->repo->dist}|{$this->repo->section}"));
        
                    try {
                        $this->exec_newLocalRepo();
                        echo '<p>Terminé <span class="greentext">✔</span></p>'; // Affichage du message à l'utilisateur
                        $this->status = 'done';
    
                    } catch(Exception $e) {
                        $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                        echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                        $this->status = 'error';
                    }

                    $this->log->steplogBuild(2);
                    $this->closeOperation();
                }
            }
        }
    }


    /**
     *  METTRE A JOUR UN REPO/SECTION
     */
    public function update() {
        global $OS_FAMILY;
        global $WWW_DIR;
        global $REPOMANAGER_YUM_DIR;
        global $DEFAULT_ENV;

        require_once("${WWW_DIR}/class/Source.php");

        if ($this->type == 'manual') {
            if ($OS_FAMILY === "Redhat") echo '<h3>METTRE A JOUR UN REPO</h3>';
            if ($OS_FAMILY === "Debian") echo '<h3>METTRE A JOUR UNE SECTION DE REPO</h3>';

            if ($this->chk_param_name() === false) ++$this->validate;
            if ($OS_FAMILY == "Debian") { if ($this->chk_param_dist() === false)    ++$this->validate; }
            if ($OS_FAMILY == "Debian") { if ($this->chk_param_section() === false) ++$this->validate; }
            if ($this->chk_param_gpgCheck() === false)  ++$this->validate;
            if ($this->chk_param_gpgResign() === false) ++$this->validate;
        }

        $this->repo->env = $DEFAULT_ENV;

        if ($this->validate() === true OR $this->validate == 0) {
            /**
             *  On récupère toutes les informations du repo/section à mettre à jour à partir de la BDD, notamment la source
             */
            $this->repo->db_getAll();

            /**
             *  On vérifie que le repo source a bien été récupéré
             */
            if (empty($this->repo->source)) {
                echo "<span class=\"redtext\">Erreur : impossible de récupérer le repo source de <b>{$this->repo->name}</b></span>";
                return false;
            }

            /**
             *  On vérifie que le repo source existe dans /etc/yum.repos.d/repomanager/
             */
            if ($OS_FAMILY == "Redhat") {
                $checkifRepoRealnameExist = exec("grep '^\\[{$this->repo->source}\\]' ${REPOMANAGER_YUM_DIR}/*.repo");
                if (empty($checkifRepoRealnameExist)) {
                    echo "<p>Erreur : Il n'existe aucun repo source pour le nom de repo [{$this->repo->source}]</p>";
                    echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                    return false;
                }
            }
            if ($OS_FAMILY == "Debian") {
                if ($this->db->countRows("SELECT * FROM sources WHERE Name = '{$this->repo->source}'") == 0) {
                    echo "<p>Erreur : L'hôte source {$this->repo->source} du repo {$this->repo->name} n'existe pas/plus</p>";
                    echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                    return false;
                }
            }

            /**
             *  Ok on a toutes les infos mais il faut vérifier que le/la repo/section existe
             */
            if ($OS_FAMILY == "Redhat") {
                if ($this->repo->existsEnv($this->repo->name, $this->repo->env) === false) {
                    echo "<p>Erreur : Il n'existe aucun repo {$this->repo->name} ".envtag($this->repo->env)." à mettre à jour.</p>";
                }
            }
            if ($OS_FAMILY == "Debian") {
                if ($this->repo->section_existsEnv($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->env) === false) {
                    echo "<p>Erreur : Il n'existe aucune section {$this->repo->section} ".envtag($this->repo->env)." du repo {$this->repo->name} (distribution {$this->repo->dist}) à mettre à jour.</p>";
                }
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                if ($OS_FAMILY == "Redhat") { echo '<p>L\'opération va mettre à jour le repo :</p>'; }
                if ($OS_FAMILY == "Debian") { echo '<p>L\'opération va mettre à jour la section :</p>'; }
                if ($OS_FAMILY == "Redhat") { echo "<span>Nom du repo :</span><span><b>{$this->repo->name} ".envtag($this->repo->env)." ({$this->repo->source})</b></span>"; }
                if ($OS_FAMILY == "Debian") {
                    echo "<span>Nom du repo :</span><span><b>{$this->repo->name} ({$this->repo->source})</b></span>";
                    echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                    echo "<span>Section :</span><span><b>{$this->repo->section}</b> ".envtag($this->repo->env)."</span>";
                }
            }

            echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

            if ($this->confirm() === true) {
                /*if ($OS_FAMILY == "Redhat") { exec("php ${WWW_DIR}/operations/updateMirror.php '{$this->repo->name}' '{$this->repo->source}' '{$this->repo->gpgCheck}' '{$this->repo->gpgResign}' >/dev/null 2>/dev/null &"); }
                if ($OS_FAMILY == "Debian") { exec("php ${WWW_DIR}/operations/updateMirror.php '{$this->repo->name}' '{$this->repo->dist}' '{$this->repo->section}' '{$this->repo->source}' '{$this->repo->gpgCheck}' '{$this->repo->gpgResign}' >/dev/null 2>/dev/null &"); }*/
                if ($OS_FAMILY == "Redhat") { exec("php ${WWW_DIR}/operations/execute.php --action='update' --name='{$this->repo->name}' --source='{$this->repo->source}' --gpgCheck='{$this->repo->gpgCheck}' --gpgResign='{$this->repo->gpgResign}' >/dev/null 2>/dev/null &"); }
                if ($OS_FAMILY == "Debian") { exec("php ${WWW_DIR}/operations/execute.php --action='update' --name='{$this->repo->name}' --dist='{$this->repo->dist}' --section='{$this->repo->section}' --source='{$this->repo->source}' --gpgCheck='{$this->repo->gpgCheck}' --gpgResign='{$this->repo->gpgResign}' >/dev/null 2>/dev/null &"); }
                echo "<script>window.location.replace('/run.php');</script>"; // On redirige vers la page de logs pour voir l'exécution
            }
        }
    }


    /**
     *  CREER UN NOUVEL ENVIRONNEMENT
     */
    public function changeEnv() {
        global $OS_FAMILY;

        if ($OS_FAMILY === "Redhat") echo '<h3>CRÉER UN NOUVEL ENVIRONNEMENT DE REPO</h3>';
        if ($OS_FAMILY === "Debian") echo '<h3>CRÉER UN NOUVEL ENVIRONNEMENT DE SECTION</h3>';
        if ($this->chk_param_name() === false) ++$this->validate;
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_dist() === false)    ++$this->validate; }
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_section() === false) ++$this->validate; }
        if ($this->chk_param_env() === false)         ++$this->validate;
        if ($this->chk_param_newEnv() === false)      ++$this->validate;
        if ($this->chk_param_description() === false) ++$this->validate;

        if ($this->validate() === true) {
            /**
             *  Ok on a toutes les infos mais pour créer un nouvel env au repo, mais il faut vérifier qu'il existe
             */
            if ($OS_FAMILY == "Redhat" AND $this->repo->existsEnv($this->repo->name, $this->repo->env) === false) {
                echo "<p>Erreur : Il n'existe aucun repo <b>{$this->repo->name}</b> en ".envtag($this->repo->env)."</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }
            if ($OS_FAMILY == "Debian" AND $this->repo->section_existsEnv($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->env) === false) {
                echo "<p>Erreur : Il n'existe aucune section <b>{$this->repo->section}</b> du repo <b>{$this->repo->name}</b> (distribution {$this->repo->dist}) en ".envtag($this->repo->env)."</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  Ensuite on vérifie si un repo existe déjà dans le nouvel env indiqué. Si c'est le cas, alors son miroir sera archivé si il n'est pas utilisé par un autre environnement
             */
            $repoArchive = 'no';
            if ($OS_FAMILY == "Redhat" AND $this->repo->existsEnv($this->repo->name, $this->repo->newEnv) === true) {
                // du coup on vérifie que le miroir du repo à archiver n'est pas utilisé par un autre environnement :
                // pour cela on récupère sa date de synchro et on regarde si elle est utilisée par un autre env :
                $result = $this->db->querySingleRow("SELECT Date FROM repos WHERE Name = '{$this->repo->name}' AND Env = '{$this->repo->newEnv}' AND Status = 'active'");
                $repoArchiveDate = $result['Date'];
                $repoToArchive = $this->db->countRows("SELECT Name, Env FROM repos WHERE Name = '{$this->repo->name}' AND Date = '$repoArchiveDate' AND Env != '{$this->repo->newEnv}' AND Status = 'active'");
                if ($repoToArchive == 0) {
                    $repoArchive = "yes"; // si le repo n'est pas utilisé par un autre environnement, alors on pourra indiquer qu'il sera archivé
                }
            }
            if ($OS_FAMILY == "Debian" AND $this->repo->section_existsEnv($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->newEnv) === true) {
                // du coup on vérifie que le miroir de la section à archiver n'est pas utilisé par un autre environnement :
                // pour cela on récupère sa date de synchro et on regarde si elle est utilisée par un autre env :
                $result = $this->db->querySingleRow("SELECT Date FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Section = '{$this->repo->section}' AND Env = '{$this->repo->newEnv}' AND Status = 'active'");
                $repoArchiveDate = $result['Date'];
                $repoToArchive = $this->db->countRows("SELECT Name, Env FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Section = '{$this->repo->section}' AND Date = '$repoArchiveDate' AND Env != '{$this->repo->newEnv}' AND Status = 'active'");
                if ($repoToArchive == 0) {
                    $repoArchive = "yes"; // si le repo n'est pas utilisé par un autre environnement, alors on pourra indiquer qu'il sera archivé
                }
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                if ($OS_FAMILY == "Redhat") { echo "<p>L'opération va faire pointer un environnement ".envtag($this->repo->newEnv)." sur le repo suivant :</p>"; }
                if ($OS_FAMILY == "Debian") { echo "<p>L'opération va faire pointer un environnement ".envtag($this->repo->newEnv)." sur la section de repo suivante : </p>"; }
                echo "<span>Nom du repo :</span><span><b>{$this->repo->name}</b></span>";
                if ($OS_FAMILY == "Debian") { echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>"; }
                if ($OS_FAMILY == "Debian") { echo "<span>Section :</span><span><b>{$this->repo->section}</b></span>"; }
                echo "<span>Env. :</span><span>".envtag($this->repo->env)."</span>";
                if ($repoArchive == "yes") echo "<p>Le miroir actuellement en ".envtag($this->repo->newEnv)." en date du <b>".DateTime::createFromFormat('Y-m-d', $repoArchiveDate)->format('d-m-Y')."</b> sera archivé</p>";
                echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';
            }

            if ($this->confirm() === true) {
                $this->repo->db_getId(); // Récupération dans $this->repo->id de l'ID en BDD du repo afin de l'inclure à l'opération
                $this->startOperation(array('id_repo_source' => $this->repo->id));

                try {
                    $this->exec_changeEnv();
                    echo '<p>Terminé <span class="greentext">✔</span></p>'; // Affichage du message à l'utilisateur
                    $this->status = 'done';

                } catch(Exception $e) {
                    $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                    echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'error';
                }

                $this->log->steplogBuild(2);
                $this->closeOperation();
            }
        }
    }

    /**
     *  DUPLIQUER UN REPO
     */
    public function duplicate() {
        global $OS_FAMILY;
        global $WWW_DIR;

        echo '<h3>DUPLIQUER UN REPO</h3>';

        if ($this->chk_param_name() === false)    ++$this->validate;
        if ($this->chk_param_newName() === false) ++$this->validate;
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_dist() === false)    ++$this->validate; }
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_section() === false) ++$this->validate; }
        if ($this->chk_param_env() === false)         ++$this->validate;
        if ($this->chk_param_description() === false) ++$this->validate;
        if ($this->chk_param_type() === false)        ++$this->validate;
        $this->chk_param_group();

        if ($this->validate() === true) {
            /**
             *  On a toutes les infos mais il faut vérifier qu'un repo du même nom n'existe pas déjà
             */
            if ($this->repo->exists($this->repo->newName) === true) {
                echo "<p>Erreur : Un repo du même nom existe déjà</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                if ($OS_FAMILY == "Redhat") echo '<p>L\'opération va créer un nouveau repo :</p>';
                if ($OS_FAMILY == "Debian") echo '<p>L\'opération va créer une nouvelle section de repo :</p>';
                if ($OS_FAMILY == "Debian") echo "<span>Section :</span><span><b>{$this->repo->section}</b></span>";
                echo "<span>Repo :</span><span><b>{$this->repo->newName}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                if ($OS_FAMILY == "Redhat") echo "<span>A partir du repo :</span><span><b>{$this->repo->name}</b> ".envtag($this->repo->env)."</span>";
                if ($OS_FAMILY == "Debian") echo "<span>A partir de la section :</span><span><b>{$this->repo->section}</b> ".envtag($this->repo->env)." du repo <b>{$this->repo->name}</b></span>";
                echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';
            }

            if ($this->confirm() === true) {
                if ($OS_FAMILY == "Redhat") exec("php ${WWW_DIR}/operations/execute.php --action='duplicate' --name='{$this->repo->name}' --newname='{$this->repo->newName}' --env='{$this->repo->env}' --group='{$this->repo->group}' --description='{$this->repo->description}' --type='{$this->repo->type}' >/dev/null 2>/dev/null &");
                if ($OS_FAMILY == "Debian") exec("php ${WWW_DIR}/operations/execute.php --action='duplicate' --name='{$this->repo->name}' --newname='{$this->repo->newName}' --dist='{$this->repo->dist}' --section='{$this->repo->section}' --env='{$this->repo->env}' --group='{$this->repo->group}' --description='{$this->repo->description}' --type='{$this->repo->type}' >/dev/null 2>/dev/null &");
                echo "<script>window.location.replace('/run.php');</script>"; // On redirige vers la page de logs pour voir l'exécution
            }
        }
    }

    /**
     *  SUPPRIMER UN REPO
     */
    public function delete() {
        global $OS_FAMILY;

        echo '<h3>SUPPRIMER UN REPO</h3>';

        if ($this->chk_param_name() === false) ++$this->validate;
        if ($OS_FAMILY == "Redhat" AND $this->chk_param_env() === false) ++$this->validate; // Pour Redhat on a besoin de l'environnement

        if ($this->validate() === true) {
            /**
             *  On a toutes les infos mais il faut vérifier que le repo mentionné existe
             */
            if ($this->repo->exists($this->repo->name) === false) {
                echo "<p>Erreur : Il n'existe aucun repo {$this->repo->name} ".envtag($this->repo->env)."</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  Debian : Ok le repo existe mais peut être que celui-ci contient plusieurs distrib et sections qui seront supprimées, on récupère les distrib et les sections concernées
             *  et on les affichera dans la demande de confirmation
             */
            if ($OS_FAMILY == "Debian") $distAndSectionsToBeDeleted = $this->db->query("SELECT Dist, Section, Env FROM repos WHERE Name = '{$this->repo->name}' AND Status = 'active'");

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                if ($OS_FAMILY == "Redhat") echo '<p>L\'opération va supprimer le repo suivant :</p>';
                if ($OS_FAMILY == "Debian") echo '<p>L\'opération va supprimer tout le contenu du repo suivant :</p>';
                echo "<span>Nom du repo :</span><span><b>{$this->repo->name}</b></span>";
                if ($OS_FAMILY == "Redhat") echo "<span>Env :</span><span>".envtag($this->repo->env)."</span>";
                if ($OS_FAMILY == "Debian") {
                    if (!empty($distAndSectionsToBeDeleted)) {
                        echo '<p>Attention, cela supprimera les distributions et sections suivantes :</p>';
                        echo '<span>';
                        while ($distAndSection = $distAndSectionsToBeDeleted->fetchArray()) {
                            $dist = $distAndSection['Dist'];
                            $section = $distAndSection['Section'];
                            $env = $distAndSection['Env'];
                            echo "<b>$dist</b> -> <b>$section</b> ".envtag($env)."<br>";
                        }
                        echo '</span>';
                    } else {
                        echo '<p>Attention, impossible de récupérer le nom des distributions et des sections impactées.<br>L\'opération supprimera tout le contenu du repo et donc les distributions et les sections qu\'il contient (tout environnement confondu)</p>';
                    }
                    echo '<p><br>Cela inclu également les sections archivées si il y en a.</p>';
                }
            }

            echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

            if ($this->confirm() === true) {
                /**
                 *  On indique à startOperation quel est l'ID du repo concerné par cette tâche.
                 *  Si Redhat on peut récupérer sont ID en BDD directement
                 *  Si Debian, on indique seulement le nom du repo qui sera supprimé, car un repo peut posséder plusieures lignes en BDD (car potentiellement plusieurs distrib/sections)
                 */
                if ($OS_FAMILY == "Redhat") {
                    $this->repo->db_getId();
                    $this->startOperation(array('id_repo_target' => $this->repo->id));
                }
                if ($OS_FAMILY == "Debian") $this->startOperation(array('id_repo_target' => $this->repo->name));

                try {
                    $this->exec_delete();
                    echo '<p>Supprimé <span class="greentext">✔</span></p>'; // Affichage du message à l'utilisateur
                    $this->status = 'done';

                } catch(Exception $e) {
                    $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                    echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'error';
                }

                $this->log->steplogBuild(1);
                $this->closeOperation();
            }
        }
    }

    /**
     *  SUPPRIMER UNE DISTRIBUTION
     */
    public function deleteDist() {

        echo '<h3>SUPPRIMER UNE DISTRIBUTION</h3>';

        if ($this->chk_param_name() === false) ++$this->validate;
        if ($this->chk_param_dist() === false) ++$this->validate;

        if ($this->validate() === true) {
            /**
             *  On a toutes les infos mais il faut vérifier que la distribution mentionnée existe
             */
            if ($this->repo->dist_exists($this->repo->name, $this->repo->dist) === false) {
                echo "<p>Erreur : Il n'existe aucune distribution <b>${repoDist}</b> du repo <b>${repoName}</b></p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  Ok la distribution existe mais peut être que celle-ci contient plusieurs sections qui seront supprimées, on récupère les sections concernées
             *  et on les affichera dans la demande de confirmation
             */
            $sectionsToBeDeleted = $this->db->query("SELECT Section, Env FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Status = 'active'");

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                echo '<p>L\'opération va supprimer tout le contenu de la distribution suivante :</p>';
                echo "<span>Repo :</span><span><b>{$this->repo->name}</b></span>";
                echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                
                if (!empty($sectionsToBeDeleted)) {
                    echo '<p><br>Attention, cela supprimera les sections suivantes :</p>';
                    echo '<span>';
                    while ($sections = $sectionsToBeDeleted->fetchArray()) {
                        $section = $sections['Section'];
                        $env = $sections['Env'];
                        echo "<b>$section</b> ".envtag($env)."<br>";
                    }
                    echo '</span>';
                } else {
                    echo '<p>Erreur : impossible de récupérer le nom des sections impactées.<br>L\'opération supprimera tout le contenu de la distribution et donc les sections qu\'elle contient (tout environnement confondu)</p>';
                }
                echo '<p><br>Cela inclu également les sections archivées si il y en a.</p>';
            }

            echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

            if ($this->confirm() === true) {
                /**
                 *  On indique à startOperation quel est le nom du repo et la distribution concerné par cette tâche.
                 */
                $this->startOperation(array('id_repo_target' => "{$this->repo->name}|{$this->repo->dist}"));

                try {
                    $this->exec_deleteDist();
                    echo '<p>Supprimée <span class="greentext">✔</span></p>'; // Affichage du message à l'utilisateur
                    $this->status = 'done';

                } catch(Exception $e) {
                    $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                    echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'error';
                }

                $this->log->steplogBuild(1);
                $this->closeOperation();
            }
        }
    }

    /**
     *  SUPPRIMER UNE SECTION
     */
    public function deleteSection() {
        echo '<h3>SUPPRIMER UNE SECTION</h3>';

        if ($this->chk_param_name() === false)    ++$this->validate;
        if ($this->chk_param_dist() === false)    ++$this->validate;
        if ($this->chk_param_section() === false) ++$this->validate;
        if ($this->chk_param_env() === false)     ++$this->validate;

        if ($this->validate() === true) {
            /**
             *  On a toutes les infos mais il faut vérifier que la section mentionnée existe
             */
            if ($this->repo->section_existsEnv($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->env) === false) {
                echo "<p>Erreur : Il n'existe aucune section <b>{$this->repo->section}</b> du repo <b>{$this->repo->name}</b>(distribution <b>{$this->repo->dist}</b>)" .envtag($this->repo->env)."</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                echo '<p>L\'opération va supprimer la section de repo suivante :</p>';
                echo "<span>Repo :</span><span><b>{$this->repo->name}</b></span>";
                echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                echo "<span>Section :</span><span><b>{$this->repo->section}</b> ".envtag($this->repo->env)."</span>";
            }

            echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

            if ($this->confirm() === true) {
                /**
                 *  On indique à startOperation quel est le nom du repo et la distribution concerné par cette tâche.
                 */
                $this->startOperation(array('id_repo_target' => "{$this->repo->name}|{$this->repo->dist}|{$this->repo->section}"));

                try {
                    $this->exec_deleteSection();
                    echo '<p>Supprimée <span class="greentext">✔</span></p>'; // Affichage du message à l'utilisateur
                    $this->status = 'done';

                } catch(Exception $e) {
                    $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                    echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'error';
                }

                $this->log->steplogBuild(1);
                $this->closeOperation();
            }
        }
    }

    public function deleteArchive() {
        global $OS_FAMILY;
        
        if ($OS_FAMILY == "Redhat") echo '<h3>SUPPRIMER UN REPO ARCHIVÉ</h3>';
        if ($OS_FAMILY == "Debian") echo '<h3>SUPPRIMER UNE SECTION ARCHIVÉE</h3>';

        if ($this->chk_param_name() === false) ++$this->validate;
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_dist() === false)    ++$this->validate; }
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_section() === false) ++$this->validate; }
        if ($this->chk_param_date() === false) ++$this->validate;

        if ($this->validate() === true) {
            /**
             *  On a toutes les infos mais il faut vérifier que le/la repo/section archivé mentionné existe
             */
            if ($OS_FAMILY == "Redhat" AND $this->repo->existsDate($this->repo->name, $this->repo->date, 'archived') === false) {
                echo "<p>Erreur : Il n'existe aucun repo archivé {$this->repo->name} en date du {$this->repo->date}</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }
            if ($OS_FAMILY == "Debian" AND $this->repo->section_existsDate($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->date, 'archived') === false) {
                echo "<p>Erreur : Il n'existe aucune section archivée {$this->repo->section} du repo {$this->repo->name} (distribution {$this->repo->dist})</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                if ($OS_FAMILY == "Redhat") echo '<p>L\'opération va supprimer le repo archivé suivant :</p>';
                if ($OS_FAMILY == "Debian") echo '<p>L\'opération va supprimer la section de repo archivée suivante :</p>';
                echo "<span>Repo :</span><span><b>{$this->repo->name}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Section :</span><span><b>{$this->repo->section}</b></span>";
                if ($OS_FAMILY == "Redhat") echo "<span>Date du repo :</span><span><b>{$this->repo->dateFormatted}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Date de la section :</span><span><b>{$this->repo->dateFormatted}</b></span>";
            }

            echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

            if ($this->confirm() === true) {
                $this->repo->db_getId_archived();
                $this->startOperation(array('id_repo_target' => $this->repo->id));

                try {
                    $this->exec_deleteArchive();
                    echo '<p>Supprimé <span class="greentext">✔</span></p>'; // Affichage du message à l'utilisateur
                    $this->status = 'done';

                } catch(Exception $e) {
                    $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                    echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'error';
                }

                $this->log->steplogBuild(1);
                $this->closeOperation();
            }
        }
    }

    public function restore() {
        global $OS_FAMILY;
        
        if ($OS_FAMILY == "Redhat") { echo '<h3>RESTAURER UN REPO ARCHIVÉ</h3>'; }
        if ($OS_FAMILY == "Debian") { echo '<h3>RESTAURER UNE SECTION ARCHIVÉE</h3>'; }

        if ($this->chk_param_name() === false) ++$this->validate;
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_dist() === false)    ++$this->validate; }
        if ($OS_FAMILY == "Debian") { if ($this->chk_param_section() === false) ++$this->validate; }
        if ($this->chk_param_date() === false) { ++$this->validate; }
        $this->repo->env = ''; // On réinitialise cette variable car elle a été set à $DEFAULT_ENV lors de l'instanciation de l'objet $this->repo. Cela pose un pb pour la fonction qui suit.
        if ($this->chk_param_newEnv() === false)      ++$this->validate;
        if ($this->chk_param_description() === false) ++$this->validate;

        if ($this->validate() === true) {
            /**
             *  On a toutes les infos mais il faut vérifier que le/la repo/section archivé mentionné existe
             */
            if ($OS_FAMILY == "Redhat" AND $this->repo->existsDate($this->repo->name, $this->repo->date, 'archived') === false) {
                echo "<p>Erreur : Il n'existe aucun repo archivé {$this->repo->name} en date du {$this->repo->dateFormatted}</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }
            if ($OS_FAMILY == "Debian" AND $this->repo->section_existsDate($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->date, 'archived') === false) {
                echo "<p>Erreur : Il n'existe aucune section archivée {$this->repo->section} du repo {$this->repo->name} (distribution {$this->repo->dist}) en date du {$this->repo->dateFormatted}</p>";
                echo '<a href="index.php" class="button-submit-large-red">Retour</a>';
                return false;
            }

            /**
             *  On vérifie si un/une repo/section du même nom existe sur l'env $this->repo->newEnv, si c'est le cas et que son miroir n'est pas utilisé par d'autres environnements, il/elle sera archivé(e)
             */
            $repoArchive = 'no'; // on déclare une variable à 'no' par défaut
            if ($OS_FAMILY == "Redhat" AND $this->repo->existsEnv($this->repo->name, $this->repo->newEnv) === true) {
                // Si le résultat précedent === true, alors il y a un miroir qui sera potentiellement archivé. 
                // On récupère sa date et on regarde si cette date n'est pas utilisée par un autre env.
                $result = $this->db->querySingleRow("SELECT Date FROM repos WHERE Name = '{$this->repo->name}' AND Env = '{$this->repo->newEnv}' AND Status = 'active'");
                $repoToBeArchivedDate = $result['Date'];
                $repoToBeArchived = $this->db->countRows("SELECT * FROM repos WHERE Name = '{$this->repo->name}' AND Date = '$repoToBeArchivedDate' AND Env != '{$this->repo->newEnv}' AND Status = 'active'");
                // Si d'autres env utilisent le miroir en date du '$repoToBeArchivedDate' alors on ne peut pas archiver. Sinon on archive :
                if ($repoToBeArchived == 0) {
                    $repoArchive = 'yes';
                }
            }
            if ($OS_FAMILY == "Debian" AND $this->repo->section_existsEnv($this->repo->name, $this->repo->dist, $this->repo->section, $this->repo->newEnv) === true) {
                // Si le résultat précedent === true, alors il y a un miroir qui sera potentiellement archivé. 
                // On récupère sa date et on regarde si cette date n'est pas utilisée par un autre env.
                $result = $this->db->querySingleRow("SELECT Date FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Section = '{$this->repo->section}' AND Env = '{$this->repo->newEnv}' AND Status = 'active'");
                $repoToBeArchivedDate = $result['Date'];
                $repoToBeArchived = $this->db->countRows("SELECT * FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Section = '{$this->repo->section}' AND Date = '$repoToBeArchivedDate' AND Env != '{$this->repo->newEnv}' AND Status = 'active'");
                // Si d'autres env utilisent le miroir en date du '$repoToBeArchivedDate' alors on ne peut pas archiver. Sinon on archive :
                if ($repoToBeArchived == 0) {
                    $repoArchive = 'yes';
                }
            }

            /**
             *  Si tout est OK alors on affiche un récapitulatif avec une demande de confirmation
             */
            if (empty($_GET['confirm'])) {
                if ($OS_FAMILY == "Redhat") echo '<p>L\'opération va restaurer le repo archivé suivant :</p>';
                if ($OS_FAMILY == "Debian") echo '<p>L\'opération va restaurer la section de repo archivée suivante :</p>';
                echo "<span>Repo :</span><span><b>{$this->repo->name}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Distribution :</span><span><b>{$this->repo->dist}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Section :</span><span><b>{$this->repo->section}</b></span>";
                if ($OS_FAMILY == "Redhat") echo "<span>Date du repo :</span><span><b>{$this->repo->dateFormatted}</b></span>";
                if ($OS_FAMILY == "Debian") echo "<span>Date de la section :</span><span><b>{$this->repo->dateFormatted}</b></span>";
                if ($OS_FAMILY == "Redhat") echo "<p>La restauration placera le repo sur l'environnement ".envtag($this->repo->newEnv)."</p>";
                if ($OS_FAMILY == "Debian") echo "<p>La restauration placera la section sur l'environnement ".envtag($this->repo->newEnv)."</p>";
                if ($repoArchive == "yes")  echo "<p>Le miroir actuellement en ".envtag($this->repo->newEnv)." en date du <b>".DateTime::createFromFormat('Y-m-d', $repoToBeArchivedDate)->format('d-m-Y')."</b> sera archivée.</p>";
            }

            echo '<span class="loading">Chargement <img src="images/loading.gif" class="icon" /></span>';

            if ($this->confirm() === true) {
                $this->repo->db_getId_archived();
                if ($OS_FAMILY == "Redhat") $this->startOperation(array('id_repo_target' => "{$this->repo->name}"));
                if ($OS_FAMILY == "Debian") $this->startOperation(array('id_repo_target' => "{$this->repo->name}|{$this->repo->dist}|{$this->repo->section}"));

                try {
                    $this->exec_restore();
                    echo "<p>Restauré en ".envtag($this->repo->newEnv)."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'done';

                } catch(Exception $e) {
                    $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log
                    echo "<p>Erreur : ".$e->getMessage()."</p>"; // Affichage du message à l'utilisateur
                    $this->status = 'error';
                }

                $this->log->steplogBuild(2);
                $this->closeOperation();
            }
        }
    }
} ?>