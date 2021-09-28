<?php
trait duplicate {
    /**
     *  DUPLIQUER UN REPO/SECTION
     */
    public function exec_duplicate() {
        global $REPOS_DIR;
        global $WWW_DIR;
        global $WWW_USER;
        global $WWW_HOSTNAME;
        global $OS_FAMILY;
        global $GPGHOME;
        global $GPG_KEYID;
        global $PID_DIR;
        global $TEMP_DIR;

        /**
         *  Démarrage de l'opération
         *  On récupère en BDD l'ID du repo/section qu'on met à jour, afin de l'indiquer à startOperation
         */
        $this->repo->db_getId();

        if ($OS_FAMILY == "Redhat") $this->startOperation(array('id_repo_source' => $this->repo->id, 'id_repo_target' => "{$this->repo->newName}"));
        if ($OS_FAMILY == "Debian") $this->startOperation(array('id_repo_source' => $this->repo->id, 'id_repo_target' => "{$this->repo->newName}|{$this->repo->dist}|{$this->repo->section}"));

        /**
         *  Ajout du PID de ce processus dans le fichier PID
         */
        $this->log->addsubpid(getmypid());

        /**
         *  Lancement du script externe qui va construire le fichier de log principal à partir des petits fichiers de log de chaque étape
         */
        $steps = 4;
        exec("php ${WWW_DIR}/operations/logbuilder.php ${PID_DIR}/{$this->log->pid}.pid {$this->log->location} ${TEMP_DIR}/{$this->log->pid} $steps >/dev/null 2>/dev/null &");
        
        try {

            ob_start();

            /**
             *  1. Génération du tableau récapitulatif de l'opération
             */
            if ($OS_FAMILY == "Redhat") echo "<h3>DUPLIQUER UN REPO</h3>";
            if ($OS_FAMILY == "Debian") echo "<h3>DUPLIQUER UNE SECTION DE REPO</h3>";

            echo '<table class="op-table">';
            if ($OS_FAMILY == "Redhat") {
                echo "<tr>
                    <th>Nom du repo source :</th>
                    <td><b>{$this->repo->name}</b> ".envtag($this->repo->env)."</td>
                </tr>";
            }
            if ($OS_FAMILY == "Debian") {
                echo "<tr>
                    <th>Nom du repo source :</th>
                    <td><b>{$this->repo->name}</b></td>
                </tr>
                <tr>
                    <th>Distribution :</th>
                    <td><b>{$this->repo->dist}</b></td>
                </tr>
                <tr>
                    <th>Section de repo :</th>
                    <td><b>{$this->repo->section}</b> ".envtag($this->repo->env)."</td>
                </tr>";
            }
            if (!empty($this->repo->newName)) {
                echo "<tr>
                    <th>Nom du nouveau repo :</th>
                    <td><b>{$this->repo->newName}</b></td>
                </tr>";
            }
            if (!empty($this->repo->description)) {
                echo "<tr>
                    <th>Description :</th>
                    <td><b>{$this->repo->description}</b></td>
                </tr>";
            }
            if (!empty($this->repo->group)) {
                echo "<tr>
                    <th>Ajout à un groupe :</th>
                    <td><b>{$this->repo->group}</b></td>
                </tr>";
            }
            echo '</table>';

            $this->log->steplog(1);
            $this->log->steplogInitialize('duplicate');
            $this->log->steplogTitle('DUPLICATION');
            $this->log->steplogLoading();

            /** 
             *  2. On vérifie que le nouveau nom du repo n'est pas vide
             */
            if (empty($this->repo->newName)) throw new Exception('le nom du nouveau est vide');

            /**
             *  3. Vérifications : 
             *  On vérifie que le repo/section source (celui qui sera dupliqué) existe bien
             *  On vérifie que le nouveau nom du repo n'existe pas déjà
             */
            if ($OS_FAMILY == "Redhat") {
                if ($this->repo->exists($this->repo->name) === false) throw new Exception("le repo à dupliquer n'existe pas");
            }
            if ($OS_FAMILY == "Debian") {
                if ($this->repo->section_exists($this->repo->name, $this->repo->dist, $this->repo->section) === false) throw new Exception("le repo à dupliquer n'existe pas");
            }
            if ($this->repo->exists($this->repo->newName) === true) throw new Exception("un repo <b>{$this->repo->newName}</b> existe déjà");

            /**
             *  4. On récupère la date et la source du repo qu'on va dupliquer
             */
            $this->repo->db_getDate();

            if ($OS_FAMILY == "Redhat") {
                // Source
                $resultSource = $this->db->querySingleRow("SELECT Source FROM repos WHERE Name = '{$this->repo->name}' AND Env = '{$this->repo->env}' AND Status = 'active'");
                // Signature 
                $resultSigned = $this->db->querySingleRow("SELECT Signed FROM repos WHERE Name = '{$this->repo->name}' AND Env = '{$this->repo->env}' AND Status = 'active'");
            }
            if ($OS_FAMILY == "Debian") {
                // Source
                $resultSource = $this->db->querySingleRow("SELECT Source FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Section = '{$this->repo->section}' AND Env = '{$this->repo->env}' AND Status = 'active'");
                // Signature
                $resultSigned = $this->db->querySingleRow("SELECT Signed FROM repos WHERE Name = '{$this->repo->name}' AND Dist = '{$this->repo->dist}' AND Section = '{$this->repo->section}' AND Env = '{$this->repo->env}' AND Status = 'active'");
            }
            $this->repo->source = $resultSource['Source'];
            $this->repo->signed = $resultSigned['Signed'];

            /**
             *  4. Création du nouveau répertoire avec le nouveau nom du repo :
             */
            if ($OS_FAMILY == "Redhat") {
                if (!file_exists("${REPOS_DIR}/{$this->repo->dateFormatted}_{$this->repo->newName}")) {
                    if (!mkdir("${REPOS_DIR}/{$this->repo->dateFormatted}_{$this->repo->newName}", 0770, true)) throw new Exception("impossible de créer le répertoire du nouveau repo <b>{$this->repo->newName}</b>");
                }
            }
            if ($OS_FAMILY == "Debian") {
                if (!file_exists("${REPOS_DIR}/{$this->repo->newName}/{$this->repo->dist}/{$this->repo->dateFormatted}_{$this->repo->section}")) {
                    if (!mkdir("${REPOS_DIR}/{$this->repo->newName}/{$this->repo->dist}/{$this->repo->dateFormatted}_{$this->repo->section}", 0770, true)) throw new Exception("impossible de créer le répertoire du nouveau repo <b>{$this->repo->newName}</b>");
                }
            }

            /**
             *  5. Copie du contenu du repo/de la section
             *  Anti-slash devant la commande cp pour forcer l'écrasement
             */
            if ($OS_FAMILY == "Redhat") exec("\cp -r ${REPOS_DIR}/{$this->repo->dateFormatted}_{$this->repo->name}/* ${REPOS_DIR}/{$this->repo->dateFormatted}_{$this->repo->newName}/", $output, $result);
            if ($OS_FAMILY == "Debian") exec("\cp -r ${REPOS_DIR}/{$this->repo->name}/{$this->repo->dist}/{$this->repo->dateFormatted}_{$this->repo->section}/* ${REPOS_DIR}/{$this->repo->newName}/{$this->repo->dist}/{$this->repo->dateFormatted}_{$this->repo->section}/", $output, $result);
            if ($result != 0) throw new Exception('impossible de copier les données du repo source vers le nouveau repo');

            /**
             *   6. Création du lien symbolique
             */
            if ($OS_FAMILY == "Redhat") exec("cd ${REPOS_DIR}/ && ln -sfn {$this->repo->dateFormatted}_{$this->repo->newName}/ {$this->repo->newName}_{$this->repo->env}", $output, $result);            
            if ($OS_FAMILY == "Debian") exec("cd ${REPOS_DIR}/{$this->repo->newName}/{$this->repo->dist}/ && ln -sfn {$this->repo->dateFormatted}_{$this->repo->section}/ {$this->repo->section}_{$this->repo->env}", $output, $result);
            if ($result != 0) throw new Exception('impossible de créer le nouveau repo');

            $this->log->steplogOK();

            /**
             *  Sur Debian il faut reconstruire les données du repo avec le nouveau nom du repo.
             */
            if ($OS_FAMILY == "Debian") {
                /**
                 *  Pour les besoins de la fonction op_createRepo(), il faut que le nom du repo à créer soit dans $this->repo->name.
                 *  Du coup on backup temporairement le nom actuel et on le remplace par $this->repo->newName
                 */
                $backupName = $this->repo->name;
                $this->repo->name = $this->repo->newName;
                $this->log->steplog(2);
                $this->op_createRepo(); // Si une exception est lancée au cours de la fonction op_createRepo() alors elle sera capturée par le try catch actuellement en place dans ce fichier (duplicate.php)

                /**
                 *  On remets en place le nom tel qu'il était
                 */
                $this->repo->name = $backupName;
            }

            $this->log->steplog(3);
            $this->log->steplogInitialize('finalize');
            $this->log->steplogTitle('FINALISATION');
            $this->log->steplogLoading();

            /**
             *  8. Insertion en BDD du nouveau repo
             */
            if ($OS_FAMILY == "Redhat") $this->db->exec("INSERT INTO repos (Name, Source, Env, Date, Time, Description, Signed, Type, Status) VALUES ('{$this->repo->newName}', '{$this->repo->source}', '{$this->repo->env}', '{$this->repo->date}', '{$this->repo->time}', '{$this->repo->description}', '{$this->repo->signed}', '{$this->repo->type}', 'active')");
            if ($OS_FAMILY == "Debian") $this->db->exec("INSERT INTO repos (Name, Source, Dist, Section, Env, Date, Time, Description, Signed, Type, Status) VALUES ('{$this->repo->newName}', '{$this->repo->source}', '{$this->repo->dist}', '{$this->repo->section}', '{$this->repo->env}', '{$this->repo->date}', '{$this->repo->time}', '{$this->repo->description}', '{$this->repo->signed}', '{$this->repo->type}', 'active')");

            $this->repo->id = $this->db->lastInsertRowID();

            /**
             *  9. Application des droits sur le nouveau repo créé
             */
            exec("find ${REPOS_DIR}/{$this->repo->newName}/ -type f -exec chmod 0660 {} \;");
            exec("find ${REPOS_DIR}/{$this->repo->newName}/ -type d -exec chmod 0770 {} \;");
            exec("chown -R ${WWW_USER}:repomanager ${REPOS_DIR}/{$this->repo->newName}/");

            $this->log->steplogOK();

            /**
             *  10. Ajout de la section à un groupe si un groupe a été renseigné
             */
            if (!empty($this->repo->group)) {
                $this->log->steplog(4);
                $this->log->steplogInitialize('addToGroup');
                $this->log->steplogTitle('AJOUT A UN GROUPE');
                $this->log->steplogLoading();

                if ($OS_FAMILY == "Redhat") $result = $this->db->query("SELECT repos.Id AS repoId, groups.Id AS groupId FROM repos, groups WHERE repos.Name = '{$this->repo->newName}' AND repos.Status = 'active' AND groups.Name = '{$this->repo->group}'");
                if ($OS_FAMILY == "Debian") $result = $this->db->query("SELECT repos.Id AS repoId, groups.Id AS groupId FROM repos, groups WHERE repos.Name = '{$this->repo->newName}' AND repos.Dist = '{$this->repo->dist}' AND repos.Section = '{$this->repo->section}' AND repos.Status = 'active' AND groups.Name = '{$this->repo->group}'");

                while ($data = $result->fetchArray()) {
                    $repoId = $data['repoId'];
                    $groupId = $data['groupId'];
                }

                if (empty($this->repo->id)) throw new Exception("impossible de récupérer l'id du repo <b>{$this->repo->newName}</b>");
                if (empty($groupId)) throw new Exception("impossible de récupérer l'id du groupe <b>{$this->repo->group}</b>");

                $this->db->exec("INSERT INTO group_members (Id_repo, Id_group) VALUES ('$repoId', '$groupId')");

                $this->log->steplogOK();
            }

            /**
             *  11. Génération du fichier de conf repo en local (ces fichiers sont utilisés pour les profils)
             *  Pour les besoins de la fonction, on set $this->repo->name = $this->repo->newName (sinon ça va générer un fichier pour le repo source, ce qu'on ne veut pas)
             */
            $this->repo->name = $this->repo->newName;
            $this->repo->generateConf('default');

            /**
             *  Mise à jour de la tâche d'opération en BDD : on indique l'ID du repo qui vient d'être ajouté en BDD, à la place de son nom complet.
             *  Ici $this->repo-id correspond désormais à l'ID en BDD du nouveau repo, la variable a été changée au cours de l'exécution de exec_duplicate()
             */
            $this->db_update_idrepo_target($this->repo->id);

            /**
             *  Passage du status de l'opération en done
             */
            $this->status = 'done';

        } catch(Exception $e) {
            $this->log->steplogError($e->getMessage()); // On transmets l'erreur à $this->log->steplogError() qui va se charger de l'afficher en rouge dans le fichier de log

            /**
             *  Passage du status de l'opération en erreur
             */
            $this->status = 'error';

            /**
             *  Cloture de l'opération
             */
            $this->log->closeStepOperation();
            $this->closeOperation();
        }

        /**
         *  Cloture de l'opération
         */
        $this->log->closeStepOperation();
        $this->closeOperation();
    }
}
?>