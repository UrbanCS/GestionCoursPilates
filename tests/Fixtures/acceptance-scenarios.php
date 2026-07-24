<?php

declare(strict_types=1);

return [
    'AT-01' => ['title' => 'Réserver une place disponible', 'layers' => ['integration', 'e2e'], 'expected' => 'Une réservation confirmed et une capacité consommée.'],
    'AT-02' => ['title' => 'Concurrence sur la dernière place', 'layers' => ['integration'], 'expected' => 'Un seul client obtient la place.'],
    'AT-03' => ['title' => 'Réservation avec crédit', 'layers' => ['integration'], 'expected' => 'Un crédit est consommé sans paiement Square.'],
    'AT-04' => ['title' => 'Achat de forfait', 'layers' => ['sandbox', 'e2e'], 'expected' => 'Paiement, forfait et crédits sont rapprochés une seule fois.'],
    'AT-05' => ['title' => 'Paiement échoué', 'layers' => ['sandbox', 'integration'], 'expected' => 'Aucune réservation confirmed.'],
    'AT-06' => ['title' => 'Webhook répété', 'layers' => ['integration'], 'expected' => 'Aucun effet métier supplémentaire.'],
    'AT-07' => ['title' => 'Annulation à temps', 'layers' => ['unit', 'integration'], 'expected' => 'Crédit restauré une seule fois.'],
    'AT-08' => ['title' => 'Annulation tardive', 'layers' => ['unit', 'integration'], 'expected' => 'Aucun crédit restauré automatiquement.'],
    'AT-09' => ['title' => 'Annulation studio', 'layers' => ['integration'], 'expected' => 'Réservations annulées et crédits restaurés.'],
    'AT-10' => ['title' => 'Ajout à la liste d’attente', 'layers' => ['integration', 'e2e'], 'expected' => 'Position attribuée sans crédit consommé.'],
    'AT-11' => ['title' => 'Première offre de liste d’attente', 'layers' => ['integration', 'cli'], 'expected' => 'La première personne admissible reçoit la seule offre active.'],
    'AT-12' => ['title' => 'Expiration d’offre', 'layers' => ['integration', 'cli'], 'expected' => 'L’offre passe au client suivant.'],
    'AT-13' => ['title' => 'QR valide', 'layers' => ['integration', 'e2e'], 'expected' => 'Présence confirmée.'],
    'AT-14' => ['title' => 'QR révoqué', 'layers' => ['unit', 'integration'], 'expected' => 'Scan refusé sans effet métier.'],
    'AT-15' => ['title' => 'Double scan', 'layers' => ['integration'], 'expected' => 'Une seule présence est enregistrée.'],
    'AT-16' => ['title' => 'Points au scan', 'layers' => ['integration'], 'expected' => 'Une seule écriture de points.'],
    'AT-17' => ['title' => 'Points au paiement', 'layers' => ['integration'], 'expected' => 'Une seule écriture de points.'],
    'AT-18' => ['title' => 'Lecteur HID et Entrée', 'layers' => ['browser'], 'expected' => 'Une saisie complète déclenche une requête unique.'],
    'AT-19' => ['title' => 'Scan incomplet', 'layers' => ['unit', 'browser'], 'expected' => 'Le scan est rejeté sans traitement métier.'],
    'AT-20' => ['title' => 'Focus de borne', 'layers' => ['browser'], 'expected' => 'Le focus revient au champ de scan.'],
    'AT-21' => ['title' => 'Mode test lecteur', 'layers' => ['browser'], 'expected' => 'Aucune requête ni modification métier.'],
    'AT-22' => ['title' => 'Dérogation refusée', 'layers' => ['acl', 'e2e'], 'expected' => 'Un employé sans droit ne peut pas contourner une règle.'],
    'AT-23' => ['title' => 'Isolation client', 'layers' => ['acl', 'http'], 'expected' => 'Aucun accès aux données d’un autre client.'],
    'AT-24' => ['title' => 'Relance cron', 'layers' => ['integration', 'cli'], 'expected' => 'Aucune séance, offre ou notification dupliquée.'],
    'AT-25' => ['title' => 'Installation non destructive', 'layers' => ['manual', 'preproduction'], 'expected' => 'Le site Joomla existant reste fonctionnel.'],
    'AT-26' => ['title' => 'Politique de désinstallation', 'layers' => ['manual', 'preproduction'], 'expected' => 'Les données sont traitées selon la politique documentée.'],
    'AT-27' => [
        'title' => 'Nouveau cycle de liste d’attente après annulation',
        'layers' => ['integration', 'e2e'],
        'expected' => 'La nouvelle offre débite un nouveau crédit, réinitialise les métadonnées et conserve l’idempotence du cycle courant.',
    ],
    'AT-28' => [
        'title' => 'Paiement direct d’une séance',
        'layers' => ['sandbox', 'integration', 'e2e'],
        'expected' => 'La place est retenue pendant le paiement et la réservation est confirmée une seule fois après un paiement Square COMPLETED.',
    ],
    'AT-29' => [
        'title' => 'Libération d’une retenue de paiement',
        'layers' => ['integration', 'cli'],
        'expected' => 'Un paiement définitivement échoué ou une commande abandonnée libère la capacité sans confirmer la réservation.',
    ],
    'AT-30' => [
        'title' => 'Portail frontal complet pour Super User',
        'layers' => ['acl', 'browser', 'e2e'],
        'expected' => 'Le Super User connecté accède aux onze écrans de gestion et peut exécuter les mêmes opérations autorisées que dans l’administration.',
    ],
    'AT-31' => [
        'title' => 'Refus du portail frontal sans permission',
        'layers' => ['acl', 'http'],
        'expected' => 'Un visiteur est dirigé vers la connexion et un utilisateur sans permission reçoit un refus sans donnée métier.',
    ],
    'AT-32' => [
        'title' => 'Conservation des secrets Square au frontal',
        'layers' => ['acl', 'integration', 'e2e'],
        'expected' => 'Les secrets Square ne sont jamais réaffichés et restent inchangés lorsque leurs champs sont enregistrés vides.',
    ],
];
