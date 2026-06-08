<template>
  <LoadingComponent :props="loading" />

  <div class="db-card">
    <div class="db-card-header">
      <h3 class="db-card-title">{{ $t("menu.time_slots") }}</h3>
    </div>

    <div class="db-card-body py-0">
      <ul class="flex flex-col" v-if="enums.dayEnums.length > 0">
        <li class="flex sm:items-center flex-col sm:flex-row border-b border-[#EFF0F6]" v-for="dayEnum in enums.dayEnums"
          :key="dayEnum">
          <!-- Slot weekenday name -->
          <p class="capitalize pt-5 sm:pt-0 w-24 flex-shrink-0 text-sm text-[#374151]">
            {{ dayLabel(dayEnum) }}
          </p>

          <!-- Slot content group -->
          <div class="flex items-center mr-4 flex-wrap border-l border-[#EFF0F6]">
            <!-- Slot time box -->
            <div class="flex items-center flex-wrap" v-for="timeSlot in timeSlots" :key="timeSlot">
              <div class="relative flex items-start gap-8 py-2 px-3 rounded-lg border border-[#EFF0F6] time-slot-gap"
                v-if="dayEnum.id == timeSlot.day">
                <SmTimeSloteDeleteComponent @click="destroy(timeSlot.id)" />
                <div>
                  <p class="text-xs whitespace-nowrap capitalize mb-1.5">
                    {{ $t("label.opening_time") }}
                  </p>
                  <p class="flex items-center gap-1">
                    <i class="lab lab-time-slots lab-font-size-16 lab-font-color-3"></i>
                    <span class="text-xs whitespace-nowrap text-[#374151]">
                      {{ timeSlot.opening_time }}
                    </span>
                  </p>
                </div>
                <div>
                  <p class="text-xs whitespace-nowrap capitalize mb-1.5">
                    {{ $t("label.closing_time") }}
                  </p>
                  <p class="flex items-center gap-1">
                    <i class="lab lab-time-slots lab-font-size-16 lab-font-color-3"></i>
                    <span class="text-xs whitespace-nowrap text-[#374151]">
                      {{ timeSlot.closing_time }}
                    </span>
                  </p>
                </div>
              </div>
              <!-- Slot add button -->
            </div>
            <TimeSlotCreateComponent :props="props" :day="dayEnum.id" />
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
<script>
import LoadingComponent from "../../components/LoadingComponent";
import TimeSlotCreateComponent from "./TimeSlotCreateComponent";
import alertService from "../../../../services/alertService";
import PaginationTextComponent from "../../components/pagination/PaginationTextComponent";
import PaginationBox from "../../components/pagination/PaginationBox";
import PaginationSMBox from "../../components/pagination/PaginationSMBox";
import appService from "../../../../services/appService";
import TableLimitComponent from "../../components/TableLimitComponent";
import SmTimeSloteDeleteComponent from "../../components/buttons/SmTimeSloteDeleteComponent";
import SmModalEditComponent from "../../components/buttons/SmModalEditComponent";
import dayEnum from "../../../../enums/modules/dayEnum";

export default {
  name: "TimeSlotListComponent",
  components: {
    TableLimitComponent,
    PaginationSMBox,
    PaginationBox,
    PaginationTextComponent,
    TimeSlotCreateComponent,
    LoadingComponent,
    SmTimeSloteDeleteComponent,
    SmModalEditComponent,
  },
  data() {
    return {
      loading: {
        isActive: false,
      },
      enums: {
        dayEnums: dayEnum,
      },
      props: {
        form: {
          opening_time: "",
          closing_time: "",
          day: "",
        },
        search: {
          paginate: 0,
          page: 1,
          per_page: 10,
          order_column: "closing_time",
          order_type: "asc",
        },
      },
    };
  },
  mounted() {
    this.list();
  },
  computed: {
    timeSlots: function () {
      return this.$store.getters["timeSlot/lists"];
    },
    pagination: function () {
      return this.$store.getters["timeSlot/pagination"];
    },
    paginationPage: function () {
      return this.$store.getters["timeSlot/page"];
    },
  },
  methods: {
    // [Wave 5 — i18n] Localize the weekday name (enum exposes EN). Map by the
    // stable numeric enum id (1=Mon..6=Sat, 0=Sun) to the FR label.* key so the
    // enum's value mapping stays intact and only the DISPLAYED name is FR.
    dayLabel: function (dayEnum) {
      const keys = {
        1: "label.day_monday",
        2: "label.day_tuesday",
        3: "label.day_wednesday",
        4: "label.day_thursday",
        5: "label.day_friday",
        6: "label.day_saturday",
        0: "label.day_sunday",
      };
      const key = keys[dayEnum.id];
      return key ? this.$t(key) : dayEnum.name;
    },
    taxTypeClass: function (type) {
      return appService.taxTypeClass(type);
    },
    textShortener: function (text, number = 30) {
      return appService.textShortener(text, number);
    },
    list: function (page = 1) {
      this.loading.isActive = true;
      this.props.search.page = page;
      this.$store
        .dispatch("timeSlot/lists", this.props.search)
        .then((res) => {
          this.loading.isActive = false;
        })
        .catch((err) => {
          this.loading.isActive = false;
        });
    },
    destroy: function (id) {
      appService
        .destroyConfirmation()
        .then((res) => {
          try {
            this.loading.isActive = true;
            this.$store
              .dispatch("timeSlot/destroy", {
                id: id,
                search: this.props.search,
              })
              .then((res) => {
                this.loading.isActive = false;
                alertService.successFlip(null, this.$t("menu.time_slots"));
              })
              .catch((err) => {
                this.loading.isActive = false;
                alertService.error(err.response.data.message);
              });
          } catch (err) {
            this.loading.isActive = false;
            alertService.error(err.response.data.message);
          }
        })
        .catch((err) => {
          this.loading.isActive = false;
        });
    },
  },
};
</script>
