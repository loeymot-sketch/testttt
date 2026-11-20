<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Retour vers l'application — Le Cayenne</title>
    {{-- [GOAL APP HYBRIDE · W4 · 2026-11-03] Page où Mollie renvoie le client qui a payé DEPUIS L'APPLICATION.
         Elle s'affiche dans le navigateur du système (SFSafariViewController / Custom Tabs) ouvert par l'application.
         Son seul rôle : rouvrir l'application par son lien profond. Elle NE LIT PAS la base, NE PAIE RIEN et
         n'affiche AUCUNE donnée de la commande : l'application relit la commande auprès du serveur, et seul le
         webhook Mollie (vérité re-lue chez Mollie) la marque payée. Même page pour tout le monde. --}}
    <style>
        :root { --noir:#0A0A0A; --orange:#FF5A1F; --jaune:#FFD93D; --blanc:#FFFFFF; --gris:#D4D4D4; }
        * { box-sizing:border-box; }
        html,body { margin:0; padding:0; background:var(--noir); color:var(--blanc); }
        body { min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
               padding:calc(env(safe-area-inset-top,0px) + 24px) 24px calc(env(safe-area-inset-bottom,0px) + 24px); }
        .sur { margin:0 0 12px; color:var(--jaune); font-weight:800; font-size:13px; letter-spacing:.14em; text-transform:uppercase; }
        h1 { margin:0 0 12px; font-size:26px; line-height:1.2; font-weight:800; }
        p.texte { margin:0 0 28px; max-width:340px; font-size:16px; line-height:1.5; color:var(--gris); }
        a.bouton { display:inline-block; min-width:240px; min-height:48px; padding:15px 24px; border-radius:14px; background:var(--orange);
                   color:var(--noir); font-weight:800; font-size:17px; line-height:1.1; text-decoration:none; }
        a.bouton:focus-visible { outline:3px solid var(--jaune); outline-offset:3px; }
        p.aide { margin:22px 0 0; max-width:340px; font-size:14px; line-height:1.5; color:var(--gris); }
    </style>
</head>
<body>
    <p class="sur">Le Cayenne</p>
    <h1>Retour vers l'application</h1>
    <p class="texte">Le résultat de votre paiement s'affiche dans l'application Le Cayenne.</p>
    <a class="bouton" id="lc-retour-app" href="{{ $lienApplication }}">Revenir dans l'application</a>
    <p class="aide">Si l'application ne s'ouvre pas, touchez le bouton ci-dessus, ou fermez cette fenêtre.</p>
    <script>
        // Tentative automatique ; certains navigateurs exigent un toucher pour ouvrir une application :
        // le bouton visible reste alors le chemin sûr.
        (function () { try { window.location.href = @json($lienApplication); } catch (e) {} })();
    </script>
</body>
</html>
