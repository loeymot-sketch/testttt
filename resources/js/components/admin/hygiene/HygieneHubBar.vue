<template>
    <nav class="hy-hubbar" aria-label="Retour au hub" data-testid="hub-bar">
        <router-link to="/admin/hygiene/home" class="hy-hubbar-link" data-testid="hub-bar-link">
            <span aria-hidden="true">←</span>
            <span>Hygiène &amp; étiquettes</span>
        </router-link>
    </nav>
</template>

<script>
/**
 * [CAYENNE HYGIÈNE 2026-10-18 · owner : « un seul bouton dans la barre latérale »] La barre latérale n'a plus qu'une entrée pour tout le
 * module ; chaque écran du module remonte donc au hub en UN appui, depuis son propre en-tête (les écrans de l'admin portent chacun leur fil
 * d'Ariane : il n'existe pas d'emplacement global). Posée en tête de chaque écran sauf l'accueil, qui EST le hub.
 */
import { scrollShellToTop } from "../../../helpers/hygieneCommon";

export default {
    name: "HygieneHubBar",
    mounted() { scrollShellToTop(); },
};
</script>

<!-- La barre porte SA feuille : les écrans d'étiquettes (commandes, imprimante) n'importent pas hygiene.css, et la barre y restait un lien nu après un F5. -->
<style src="./hygiene.css"></style>
