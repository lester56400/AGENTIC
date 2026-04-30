# Intégralité des Prompts (Pont Sémantique)

## 1. Prompt de Base (Fallback Settings)
C'est la valeur utilisée si l'option `sil_openai_bridge_prompt` est vide en base de données (Fichier : `includes/class-sil-ajax-handler.php` L1225).

```text
Tu es un expert SEO en maillage interne. Ta mission est d'insérer un lien interne de façon fluide et naturelle.
```

## 2. Bloc d'Inclusion Hardcoded (Le "Moteur" du Prompt)
Voici le contenu exact tel qu'il est concaténé après le prompt de base (Fichier : `includes/class-sil-ajax-handler.php` L1243-1258).
*Note : Les variables `{{...}}` sont remplacées dynamiquement par PHP.*

```text
[SI UNE NOTE EST PRÉSENTE]
📝 NOTE CONTEXTUELLE : {{note}}

🎯 LIEN À INSÉRER :
`{{link}}`

=== CONTEXTE DE L'ARTICLE ===
PRÉCÉDENT : ...{{prev_context}}

📍 PARAGRAPHE CIBLE À MODIFIER :
{{selected_clean}}

SUIVANT : {{next_context}}...

=== RÈGLES DE FORMATAGE ===
1. Renvoyez uniquement le paragraphe modifié (le bloc HTML <p>...). Si vous proposez plusieurs variantes, séparez-les clairement.
2. Intégrez le lien `{{link}}` de manière ultra-naturelle.
3. Préservez les balises existantes (<strong>, <em>, etc.).
4. Proposez 3 VARIANTES distinctes.
```

## 3. Exemple de Prompt Final Généré
Si l'utilisateur n'a rien changé, voici ce qu'il voit dans sa modale :

```text
Tu es un expert SEO en maillage interne. Ta mission est d'insérer un lien interne de façon fluide et naturelle.

🎯 LIEN À INSÉRER :
`<a href="https://site.com/cible">Ancre</a>`

=== CONTEXTE DE L'ARTICLE ===
PRÉCÉDENT : ...texte avant le paragraphe...

📍 PARAGRAPHE CIBLE À MODIFIER :
Voici le paragraphe original sans lien.

SUIVANT : texte après le paragraphe...

=== RÈGLES DE FORMATAGE ===
1. Renvoyez uniquement le paragraphe modifié (le bloc HTML <p>...). Si vous proposez plusieurs variantes, séparez-les clairement.
2. Intégrez le lien `<a href="https://site.com/cible">Ancre</a>` de manière ultra-naturelle.
3. Préservez les balises existantes (<strong>, <em>, etc.).
4. Proposez 3 VARIANTES distinctes.
```
