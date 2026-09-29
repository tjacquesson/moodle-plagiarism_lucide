<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * French strings for plagiarism_lucide.
 *
 * @package    plagiarism_lucide
 * @copyright  2026 Lucide
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['analysedon'] = 'Analysé le';
$string['analysedtext'] = 'Texte analysé';
$string['analysenow'] = 'Analyser';
$string['apikey'] = 'Clé API Lucide';
$string['apikey_help'] = 'Créez une clé dans votre compte Lucide, rubrique API, en cochant les trois droits : lancer des analyses, importer des fichiers, supprimer des analyses. La clé ne s\'affiche qu\'une fois. Elle est enregistrée dans la base Moodle comme les autres réglages : toute personne qui accède à la base ou à ses sauvegardes peut la lire.';
$string['apikeyrequired'] = 'Saisissez une clé API Lucide.';
$string['assignment'] = 'Devoir';
$string['authblocked'] = 'L\'envoi vers Lucide est arrêté : la clé a été refusée ({$a}). Vérifiez la clé et l\'abonnement Lucide, puis lancez le test de connexion.';
$string['badge_ai'] = 'IA probable';
$string['badge_human'] = 'Humain probable';
$string['badge_maybe'] = 'Incertain';
$string['badge_unknown'] = 'Analysé';
$string['badge_veryai'] = 'IA détectée';
$string['badge_veryhuman'] = 'Humain';
$string['connection'] = 'Connexion';
$string['connectionfailed'] = 'Lucide a refusé la connexion : {$a->code}. {$a->message}';
$string['connectionok'] = 'Connecté. Modèle {$a->model}, formule {$a->tier}, jusqu\'à {$a->maxwords} mots par texte. Crédits disponibles : {$a->available} (réservés : {$a->reserved}).';
$string['coverage_long'] = '{$a} % du texte est surligné comme ressemblant à de l\'IA.';
$string['coverage_short'] = '{$a} % surligné';
$string['creditsused'] = 'Crédits utilisés';
$string['cronlate'] = 'La tâche Lucide n\'a pas tourné depuis plus de 10 minutes. Vérifiez que le cron de Moodle s\'exécute chaque minute.';
$string['defaultenabled'] = 'Activé par défaut sur les nouveaux devoirs';
$string['defaultenabled_help'] = 'Chaque enseignant peut toujours désactiver Lucide dans son devoir.';
$string['deleteremote'] = 'Effacer la copie chez Lucide dès que le rapport est dans Moodle';
$string['deleteremote_help'] = 'Recommandé. Dès que le rapport est enregistré dans Moodle, le plugin demande à Lucide d\'effacer le texte et son résultat. Sans cette option, Lucide les garde 7 jours.';
$string['deleterightmissing'] = 'La clé ne permet pas d\'effacer les analyses chez Lucide : les copies y restent 7 jours. Créez une clé avec les trois droits (lancer des analyses, importer des fichiers, supprimer des analyses) et collez-la ici.';
$string['disclosure'] = 'Avis affiché aux étudiants';
$string['disclosure_default'] = 'Votre travail sera analysé par Lucide, un outil qui détecte les textes générés par intelligence artificielle. Seuls vos enseignants voient le résultat. Lucide efface votre texte de ses serveurs dès l\'analyse terminée.';
$string['disclosure_help'] = 'Affiché sur la page de dépôt des devoirs où Lucide est activé. Laissez vide pour le texte par défaut.';
$string['educationalnotice'] = 'Cette analyse fournit un indice à examiner avec le travail et son contexte. Elle ne constitue pas, à elle seule, une preuve de fraude.';
$string['enabled'] = 'Activer Lucide';
$string['enabled_help'] = 'Vérifiez aussi que les plugins de plagiat sont activés pour le site (Fonctions avancées).';
$string['enableforactivity'] = 'Analyser les remises avec Lucide';
$string['enableforactivity_help'] = 'Chaque texte en ligne et chaque fichier PDF, Word (docx) ou texte remis est analysé en arrière-plan. L\'enseignant voit le verdict à côté du travail. Coût : 1 crédit Lucide pour 100 mots.';
$string['error_credit_budget_exceeded'] = 'plafond de crédits dépassé';
$string['error_credit_replenishment_pending'] = 'recharge de crédits en cours';
$string['error_engine_unavailable'] = 'Lucide indisponible';
$string['error_extracted_text_too_large'] = 'trop long';
$string['error_file_rejected'] = 'fichier refusé';
$string['error_insufficient_credits'] = 'crédits insuffisants';
$string['error_no_extractable_text'] = 'aucun texte lisible (document scanné ?)';
$string['error_other'] = 'non analysé';
$string['error_payload_too_large'] = 'fichier de plus de 10 Mo';
$string['error_report_unavailable'] = 'rapport indisponible';
$string['error_text_too_long'] = 'trop long';
$string['error_text_too_short'] = 'trop court pour être analysé';
$string['error_timeout'] = 'Lucide injoignable';
$string['error_unsupported_format'] = 'format non pris en charge';
$string['formheader'] = 'Lucide — détection de texte généré par IA';
$string['health_analysing'] = 'En cours d\'analyse';
$string['health_blocked'] = 'En attente de crédits';
$string['health_completed'] = 'Rapports disponibles';
$string['health_extracting'] = 'Fichiers en cours de lecture';
$string['health_failed'] = 'Non analysables';
$string['health_lastcontact'] = 'Dernier échange avec Lucide';
$string['health_lastcron'] = 'Dernière exécution de la tâche';
$string['health_queued'] = 'En attente d\'envoi';
$string['health_remotedeletes'] = 'Effacements chez Lucide à confirmer';
$string['health_unsupported'] = 'Formats non pris en charge';
$string['keptuntil'] = 'Rapport conservé jusqu\'au';
$string['legend_ai'] = 'Ressemble à de l\'IA';
$string['legend_ai_medium'] = 'Ressemble à de l\'IA, moins sûr';
$string['lowconfidence'] = 'Texte court : le verdict est moins fiable.';
$string['lucide:enable'] = 'Activer ou désactiver Lucide dans une activité';
$string['lucide:requestscan'] = 'Demander l\'analyse Lucide d\'une remise';
$string['lucide:viewreport'] = 'Voir les rapports Lucide';
$string['maxcredits'] = 'Crédits maximum par texte';
$string['maxcredits_help'] = 'Un texte qui coûterait davantage n\'est pas analysé. 1 crédit pour 100 mots : 200 crédits couvrent 20 000 mots.';
$string['maxcreditsinvalid'] = 'Saisissez un nombre de crédits d\'au moins 1.';
$string['modelinfo'] = 'Modèle {$a->model}, règle de score du {$a->policy}.';
$string['nosegments_diffuse'] = 'L\'ensemble du texte ressemble à de l\'IA, sans passage qui se détache.';
$string['nosegments_human_verdict'] = 'Aucun passage n\'est surligné quand le verdict est humain.';
$string['nosegments_unavailable'] = 'Le détail par passage n\'est pas disponible pour ce texte.';
$string['notenabled'] = 'Lucide n\'est pas activé pour cette activité.';
$string['plagiarismdisabled'] = 'Les plugins de plagiat sont désactivés pour le site. Activez-les dans les <a href="{$a}">Fonctions avancées</a>.';
$string['pluginname'] = 'Lucide';
$string['privacy:export'] = 'Lucide';
$string['privacy:metadata:lucide'] = 'Pour détecter les textes générés par IA, le plugin envoie à Lucide le texte des remises. Aucun nom, adresse e-mail ou intitulé de cours n\'est transmis ; un texte peut néanmoins contenir des données personnelles écrites par son auteur.';
$string['privacy:metadata:lucide:content'] = 'Texte en ligne de la remise.';
$string['privacy:metadata:lucide:file'] = 'Fichier remis (PDF, Word ou texte), envoyé sous un nom neutre.';
$string['privacy:metadata:plagiarism_lucide_src'] = 'Analyses des remises et leurs rapports.';
$string['privacy:metadata:plagiarism_lucide_src:filename'] = 'Nom du fichier analysé, conservé dans Moodle uniquement.';
$string['privacy:metadata:plagiarism_lucide_src:report'] = 'Texte analysé et passages surlignés.';
$string['privacy:metadata:plagiarism_lucide_src:score'] = 'Score de décision du modèle.';
$string['privacy:metadata:plagiarism_lucide_src:submissionid'] = 'Remise analysée.';
$string['privacy:metadata:plagiarism_lucide_src:timecreated'] = 'Date de la demande.';
$string['privacy:metadata:plagiarism_lucide_src:userid'] = 'Auteur de la remise.';
$string['privacy:metadata:plagiarism_lucide_src:verdict'] = 'Verdict de l\'analyse.';
$string['queuehealth'] = 'Activité';
$string['reportretention'] = 'Conserver les rapports dans Moodle pendant';
$string['reportretention_help'] = 'Passé ce délai, le rapport est effacé de Moodle. La suppression de la remise, de l\'activité ou de l\'utilisateur l\'efface aussitôt.';
$string['reporttitle'] = 'Analyse IA Lucide';
$string['scanexisting'] = 'Analyser aussi les {$a} remises déjà déposées';
$string['scanexisting_help'] = 'Activer Lucide n\'envoie pas les travaux remis avant. Cochez cette case pour les analyser aussi. Chaque texte consomme des crédits.';
$string['scanrequested'] = 'Analyse demandée. Le résultat apparaîtra d\'ici quelques minutes.';
$string['settingsintro'] = 'Lucide détecte les textes générés par intelligence artificielle dans les travaux rédigés en français. Les remises sont analysées en arrière-plan ; une panne de Lucide n\'empêche jamais un étudiant de remettre son travail.';
$string['source'] = 'Source';
$string['source_file'] = 'Fichier : {$a}';
$string['source_file_hidden'] = 'Fichier remis (correction anonyme)';
$string['source_onlinetext'] = 'Texte en ligne';
$string['status_blocked'] = 'En attente de crédits';
$string['status_expired'] = 'Rapport expiré';
$string['status_failed'] = 'Non analysé';
$string['status_none'] = 'Pas encore analysé';
$string['status_pending'] = 'Analyse en cours';
$string['task_cleanup'] = 'Lucide : effacer les rapports expirés';
$string['task_process_queue'] = 'Lucide : envoyer les remises et récupérer les rapports';
$string['testconnection'] = 'Tester la connexion';
$string['verdict'] = 'Verdict';
$string['verdict_ai'] = 'Probablement écrit par une IA';
$string['verdict_human'] = 'Probablement humain';
$string['verdict_maybe'] = 'Peut-être écrit par une IA';
$string['verdict_unknown'] = 'Analysé';
$string['verdict_veryai'] = 'Très probablement écrit par une IA';
$string['verdict_veryhuman'] = 'Très probablement humain';
$string['viewreport'] = 'Voir le rapport';
$string['wordcount'] = 'Longueur analysée';
$string['words'] = '{$a} mots';
