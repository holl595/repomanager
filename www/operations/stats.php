<?php
/**
 *  Génération des données de statistiques des repos
 *  Les actions sont exécutées par cron avec l'utilisateur $WWW_USER
 */

$WWW_DIR = dirname(__FILE__, 2);

/**
 *  Import des variables et fonctions nécessaires, ne pas changer l'ordre des requires
 */
require_once("${WWW_DIR}/functions/load_common_variables.php");
require_once("${WWW_DIR}/class/Repo.php");
require_once("${WWW_DIR}/class/Database.php");

$repo = new Repo();

if ($CRON_STATS_ENABLED == "yes") {
    /**
     *  On récupère toute la liste des repos actifs
     */
    $reposList = $repo->listAll();

    if (!empty($reposList)) {
        foreach($reposList as $repo) {
            $repoId = $repo['Id'];
            $repoName = $repo['Name'];
            if ($OS_FAMILY == "Debian") {
                $repoDist = $repo['Dist'];
                $repoSection = $repo['Section'];
            }
            $repoEnv = $repo['Env'];

            /**
             *  Ouverture de la BDD
             */
            $stats_db = new Database_stats();

            if ($OS_FAMILY == "Redhat") {
                if (file_exists("${REPOS_DIR}/${repoName}_${repoEnv}")) {
                    /**
                     *  Calcul de la taille du repo
                     */
                    $repoSize = exec("du -s ${REPOS_DIR}/${repoName}_${repoEnv}/ | awk '{print $1}'");

                    /**
                     *  Calcul du nombre de paquets présents dans le repo
                     */
                    $packagesCount = exec("find ${REPOS_DIR}/${repoName}_${repoEnv}/ -type f -name '*.rpm' | wc -l");
                }
            }
            if ($OS_FAMILY == "Debian") {
                if (file_exists("${REPOS_DIR}/${repoName}/${repoDist}/${repoSection}_${repoEnv}")) {
                    /**
                     *  Calcul de la taille de la section
                     */
                    $repoSize = exec("du -s ${REPOS_DIR}/${repoName}/${repoDist}/${repoSection}_${repoEnv}/ | awk '{print $1}'");

                    /**
                     *  Calcul du nombre de paquets présents dans la section
                     */
                    $packagesCount = exec("find ${REPOS_DIR}/${repoName}/${repoDist}/${repoSection}_${repoEnv}/ -type f -name '*.deb' | wc -l");
                }
            }

            /**
             *  Ajout de la taille dans la table size
             */
            if (!empty($repoSize)) {
                $stmt = $stats_db->prepare("INSERT INTO stats (Date, Time, Id_repo, Size, Packages_count) VALUES (:date, :time, :id_repo, :size, :packages_count)");
                $stmt->bindValue(':date', date('Y-m-d'));
                $stmt->bindValue(':time', date('H:i:s'));
                $stmt->bindValue(':id_repo', $repoId);
                $stmt->bindValue(':size', $repoSize);
                $stmt->bindValue(':packages_count', $packagesCount);
                $stmt->execute();
            }
        }
    }
}

// Vérification des erreurs et ajout dans le fichier de log si c'est le cas
file_put_contents($CRON_STATS_LOG, 'Status="OK"'.PHP_EOL);
?>