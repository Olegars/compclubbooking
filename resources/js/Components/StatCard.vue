<template>
    <div class="bg-white/[0.02] border border-white/[0.05] p-6 rounded-2xl">
        <div class="text-[10px] uppercase tracking-widest text-white/40 mb-2">{{ label }}</div>
        <div class="flex items-end gap-2 text-2xl font-mono text-[#22c55e]">
            {{ value }} <span class="text-[10px] opacity-50 mb-1">{{ unit }}</span>
        </div>
    </div>
</template>
<script setup>
defineProps(['label', 'value', 'unit'])
</script>
