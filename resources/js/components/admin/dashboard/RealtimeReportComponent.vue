<template>
    <div class="col-12 sm:col-12 xl:col-12 mb-6">
        <h4 class="font-semibold text-[22px] leading-[34px] mb-3 text-orange-700">Suivi en direct</h4>
        <!--
            [G5 · T5.1 2026-09-03] LE DRAPEAU EXISTAIT, IL NE CHANGEAIT RIEN À L'ÉCRAN.
            Un correctif antérieur avait ajouté `failed` pour qu'une 403 ne laisse plus
            « 0,00 € ». Bien vu — mais le rendu retenu était `if (failed) return '—'` d'un
            côté et `report.average_ticket ?? '—'` de l'autre : LE MÊME CARACTÈRE dans les
            deux branches. « — » quand le serveur ne renvoie pas de ticket moyen, « — »
            quand le serveur ne répond pas du tout. C'est la forme la plus coûteuse du
            défaut : un correctif qui a l'air appliqué.
        -->
        <p v-if="failed" class="text-sm text-red-600 mb-3" data-testid="realtime-report-error">
            Suivi en direct interrompu — ce chiffre n'a pas pu être lu, ce n'est pas un zéro.
        </p>
        <div class="row">
            <div class="col-12 sm:col-4">
                <!--
                    [2026-09-02] `text-white` posé sur le PARENT ne suffit pas : public/css/app.css
                    porte une règle directe `h1, h2, h3, h4, h5, h6 { color: rgb(31 31 57) }` qui bat
                    la couleur héritée. Mesuré dans le navigateur sur ce bloc : libellé ET valeur en
                    rgb(31,31,57) sur fond rgb(26,26,26) — contraste 1,088:1, là où WCAG 2.1 exige
                    4,5:1. Le ticket moyen est le seul chiffre en direct de cet écran, et il était
                    illisible. Les tuiles voisines portent déjà `text-white` sur le titre lui-même.
                    [G5 · T5.2 2026-09-03] Ce correctif n'avait AUCUNE assertion : les crochets
                    `data-testid` ci-dessous la rendent enfin possible.
                -->
                <div class="p-6 rounded-2xl flex flex-col justify-center items-center shadow-lg bg-[#1A1A1A] text-white"
                     data-testid="realtime-ticket-card">
                    <h3 class="font-medium text-lg mb-2 opacity-90 text-white" data-testid="realtime-ticket-label">Ticket Moyen</h3>
                    <h4 class="font-bold text-4xl text-white" data-testid="realtime-ticket">{{ displayTicket }}</h4>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "RealtimeReportComponent",
    data() {
        return {
            report: {},
            timer: null,
            loaded: false,
            failed: false,
        }
    },
    computed: {
        displaySales() {
            if (this.failed) return '—';
            if (!this.loaded) return '…';
            return this.report.daily_sales ?? '—';
        },
        displayOrders() {
            if (this.failed) return '—';
            if (!this.loaded) return '…';
            return this.report.daily_orders ?? '—';
        },
        displayTicket() {
            if (this.failed) return '—';
            if (!this.loaded) return '…';
            return this.report.average_ticket ?? '—';
        },
    },
    mounted() {
        this.fetchData();
        this.timer = setInterval(this.fetchData, 30000);
    },
    beforeUnmount() {
        clearInterval(this.timer);
    },
    methods: {
        fetchData() {
            return this.$store.dispatch('dashboard/realtimeReport').then(res => {
                this.report = res.data.data || {};
                this.loaded = true;
                this.failed = false;
            }).catch(() => {
                // Avant : une 403/500 laissait 0,00 € — journée vide, alors
                // que le logiciel n'avait rien lu.
                // [G5 · T5.1] Le drapeau ne suffit pas : c'est la BANNIÈRE du template
                // qui rend l'échec visible. Sans elle, `failed` ne changeait rien.
                this.failed = true;
                this.loaded = true;
            });
        }
    }
}
</script>
