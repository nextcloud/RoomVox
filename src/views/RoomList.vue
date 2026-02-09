<template>
    <div class="room-list">
        <div class="room-list__header">
            <h2>{{ $t('Rooms') }}</h2>
            <NcButton type="primary" @click="$emit('create')">
                <template #icon>
                    <Plus :size="20" />
                </template>
                {{ $t('New Room') }}
            </NcButton>
        </div>

        <NcEmptyContent
            v-if="!loading && rooms.length === 0"
            :name="$t('No rooms configured')"
            :description="$t('Create your first room to get started')">
            <template #icon>
                <DoorOpen :size="64" />
            </template>
            <template #action>
                <NcButton type="primary" @click="$emit('create')">
                    {{ $t('New Room') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <div v-if="loading" class="room-list__loading">
            <NcLoadingIcon :size="44" />
        </div>

        <div v-if="!loading && rooms.length > 0" class="room-list__card">
            <table class="room-list__table">
                <thead>
                    <tr>
                        <th>{{ $t('Name') }}</th>
                        <th>{{ $t('Location') }}</th>
                        <th>{{ $t('Capacity') }}</th>
                        <th>{{ $t('Auto-accept') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th class="th-actions">{{ $t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="room in rooms"
                        :key="room.id"
                        class="room-list__row"
                        @click="$emit('select', room)">
                        <td class="room-name">
                            <DoorOpen :size="16" />
                            <span>{{ room.name }}</span>
                        </td>
                        <td>{{ room.location || '—' }}</td>
                        <td>{{ room.capacity || '—' }}</td>
                        <td>
                            <span :class="room.autoAccept ? 'badge badge--success' : 'badge badge--neutral'">
                                {{ room.autoAccept ? $t('Yes') : $t('No') }}
                            </span>
                        </td>
                        <td>
                            <span :class="room.active ? 'badge badge--success' : 'badge badge--warning'">
                                {{ room.active ? $t('Active') : $t('Inactive') }}
                            </span>
                        </td>
                        <td class="td-actions" @click.stop>
                            <NcActions>
                                <NcActionButton @click="$emit('select', room)">
                                    <template #icon>
                                        <Pencil :size="20" />
                                    </template>
                                    {{ $t('Edit') }}
                                </NcActionButton>
                            </NcActions>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import Plus from 'vue-material-design-icons/Plus.vue'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

defineProps({
    rooms: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
})

defineEmits(['select', 'create', 'refresh'])
</script>

<style scoped>
.room-list__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.room-list__header h2 {
    font-size: 20px;
    font-weight: 700;
}

.room-list__loading {
    display: flex;
    justify-content: center;
    padding: 60px;
}

.room-list__card {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
}

.room-list__table {
    width: 100%;
    border-collapse: collapse;
}

.room-list__table th {
    text-align: left;
    padding: 12px 16px;
    background: var(--color-background-dark);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    border-bottom: 1px solid var(--color-border);
}

.th-actions {
    width: 60px;
    text-align: center !important;
}

.room-list__table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
}

.room-list__row {
    cursor: pointer;
    transition: background-color 0.1s;
}

.room-list__row:hover {
    background-color: var(--color-background-hover);
}

.room-list__row:last-child td {
    border-bottom: none;
}

.td-actions {
    text-align: center;
}

.room-name {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}

.badge--success {
    background: var(--color-success-element-light);
    color: var(--color-success-text);
}

.badge--warning {
    background: var(--color-warning-element-light);
    color: var(--color-warning-text);
}

.badge--neutral {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
</style>
