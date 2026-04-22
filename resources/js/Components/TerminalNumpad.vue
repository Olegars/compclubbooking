<script setup>
// ВАЖНО: Мы убрали import { defineEmits } from 'vue';

const emit = defineEmits(['input', 'delete', 'clear']);

const keys = [
    '1', '2', '3',
    '4', '5', '6',
    '7', '8', '9'
];

const handleInput = (key) => emit('input', key);
const handleDelete = () => emit('delete');
const handleClear = () => emit('clear');

const btnBaseClass = "h-20 md:h-24 bg-[#0a0a0a] border border-[#22c55e]/30 rounded-2xl flex items-center justify-center text-4xl md:text-5xl font-mono font-black text-[#22c55e] transition-all duration-75 ease-in-out shadow-[0_0_10px_rgba(34,197,94,0.05)] hover:bg-[#111] hover:border-[#22c55e]/80 hover:shadow-[0_0_20px_rgba(34,197,94,0.3)] hover:-translate-y-1 active:scale-95 active:shadow-none active:translate-y-0 active:bg-[#22c55e] active:text-black cursor-pointer";

const actionBtnClass = "text-3xl md:text-4xl border-white/10 text-white/50 bg-[#050505]";
</script>
