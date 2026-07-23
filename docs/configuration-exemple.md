# Configuration d’exemple sans secret

Configurer les options dans **Composants → Memi Pilates → Options** après l’installation. Les clés Square privées ne doivent pas être placées dans ce dépôt, dans un menu Joomla, ni dans un fichier sous la racine Web.

| Paramètre | Exemple de préproduction | Remarque |
| --- | --- | --- |
| Fuseau horaire | `America/Toronto` | Les dates stockées restent en UTC. |
| Devise | `CAD` | Montants stockés en cents. |
| Délai d’annulation | `12` heures | Ajustable selon la politique du studio. |
| Promotion automatique de l’attente | Activée | Désactiver pour imposer la promotion manuelle. |
| Durée d’une offre d’attente | `60` minutes | La place est retenue jusqu’à l’acceptation, au retrait ou à l’expiration. |
| Rappels | `24,2` | Heures avant la séance. |
| Tentatives de notification | `5` | Après la dernière tentative, la notification passe en échec définitif. |
| Délai initial de reprise | `5` minutes | Reprise exponentielle, plafonnée à 24 heures. |
| Environnement Square | `sandbox` | Passer en production seulement après la campagne de préproduction. |
| Application ID Square | Identifiant public Sandbox | Peut être enregistré dans l’option Joomla. |
| Location ID Square | Identifiant public Sandbox | Peut être enregistré dans l’option Joomla. |

Les secrets sont injectés par le mécanisme protégé offert par l’hébergement :

~~~text
MEMI_SQUARE_ACCESS_TOKEN=<valeur-protégée-non-versionnée>
MEMI_SQUARE_WEBHOOK_SIGNATURE_KEY=<valeur-protégée-non-versionnée>
~~~

Configurer également l’URL HTTPS exacte du webhook dans Square et dans l’option du composant. Ne jamais mettre une valeur réelle dans les captures, les tickets, les tests versionnés ou les journaux.

Une annulation admissible restaure réellement le crédit utilisé. Si le forfait d’origine a expiré entre la réservation et l’annulation, ses crédits inutilisés sont d’abord soldés, puis seuls les crédits rendus par l’annulation passent dans l’état spécial `restored` et demeurent consommables. Les autres crédits expirés ne sont pas réactivés.
