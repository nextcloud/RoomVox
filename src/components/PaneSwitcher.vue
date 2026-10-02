<template>
    <!-- The pane switcher from the VoxCloud design guidelines (§7, "A long
         settings page switches panes"): a radio group, so the row has an
         accessible name and the active pane is its checked state, and every
         option carries a 20px icon chosen for what is behind it. -->
    <NcRadioGroup class="pane-switcher"
        :model-value="modelValue"
        :label="label"
        hide-label
        @update:model-value="$emit('update:modelValue', $event)">
        <NcRadioGroupButton v-for="pane in panes"
            :key="pane.id"
            :value="pane.id"
            :label="pane.label">
            <template #icon>
                <component :is="pane.icon" :size="20" />
            </template>
        </NcRadioGroupButton>
    </NcRadioGroup>
</template>

<script setup>
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'

defineProps({
    /** Id of the pane shown */
    modelValue: { type: String, required: true },
    /** Name of the row for assistive technology */
    label: { type: String, required: true },
    /** [{ id, label, icon }], icon being a vue-material-design-icons component */
    panes: { type: Array, required: true },
})

defineEmits(['update:modelValue'])
</script>

<style scoped>
.pane-switcher {
    margin-block-end: calc(5 * var(--default-grid-baseline));
}

/* The grouped pills share the row equally (flex: 1 1, a zero basis) and do
   not wrap by design. A label stays on one line, so each pill is at least as
   wide as its own icon and label, and a row that no longer fits wraps instead
   of pushing a label out of its pill. Measured in Dutch at 1280px:
   "Import / Export" and "Ondersteuning" sat below their pills. */
.pane-switcher :deep([class*='radioGroupButton__label']) {
    white-space: nowrap;
}

.pane-switcher :deep([class*='ncFormBox']) {
    flex-wrap: wrap;
}

.pane-switcher :deep([class*='ncFormBox__item']) {
    flex: 1 1 auto;
    min-width: max-content;
}

@media (max-width: 760px) {
    .pane-switcher :deep([class*='ncFormBox']) {
        gap: calc(2 * var(--default-grid-baseline));
    }

    /* A pill is as wide as its label, or the last one on a wrapped line
       stretches and reads as the selected one. */
    .pane-switcher :deep([class*='ncFormBox__item']) {
        flex: 0 0 auto;
    }
}
</style>
