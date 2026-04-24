<div v-if="showAgeWarning"
     class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] bg-[#050505] border-2 border-yellow-500/50 rounded-[40px] p-10 z-[9999995] shadow-[0_0_100px_rgba(234,179,8,0.2)] animate-in zoom-in duration-300">

<div class="flex items-center gap-4 mb-6">
    <div class="w-12 h-12 rounded-full bg-yellow-500/10 flex items-center justify-center text-yellow-500 text-2xl animate-pulse">
        ⚠️
    </div>
    <h2 class="text-yellow-500 text-2xl font-black uppercase italic tracking-tighter">Внимание: Ночной протокол</h2>
</div>

<p class="text-white/80 text-sm leading-relaxed mb-8 font-medium">
    Согласно законодательству, посещение клуба гражданами младше <span class="text-yellow-500 font-bold">16 лет</span>
    в период с <span class="text-white font-bold">22:00 до 06:00</span> запрещено без сопровождения законных представителей.
    <br><br>
    Продолжая бронирование на это время, вы подтверждаете, что вам уже есть 16 лет.
</p>

<div class="flex gap-4">
    <button @click="closeAllModals"
            class="flex-1 py-4 rounded-2xl border border-white/10 text-white/40 uppercase text-[10px] font-black tracking-widest hover:text-white transition-all">
        Мне меньше 16
    </button>
    <button @click="handleAgeConfirm"
            class="flex-[2] py-4 bg-yellow-500 hover:bg-yellow-400 text-black uppercase text-[12px] font-black italic tracking-widest rounded-2xl transition-all shadow-[0_0_20px_rgba(234,179,8,0.2)]">
        Да, мне есть 16
    </button>
</div>
</div>
