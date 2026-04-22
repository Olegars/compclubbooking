<template>
  <div class="max-w-sm mx-auto animate-in fade-in duration-700">
    <div class="space-y-6">
      <div class="group">
        <label class="block text-[10px] text-slate-500 uppercase mb-2 ml-1">Никнейм</label>
        <input type="text" class="w-full bg-transparent border-b border-white/10 p-2 focus:border-reactor-neon outline-none transition-colors" placeholder="PLAYER_1">
      </div>
      <div class="group">
        <label class="block text-[10px] text-slate-500 uppercase mb-2 ml-1">Телефон</label>
        <input type="tel" class="w-full bg-transparent border-b border-white/10 p-2 focus:border-reactor-neon outline-none transition-colors" placeholder="+7 (___) ___ __ __">
      </div>
      <button class="w-full bg-reactor-neon text-black py-4 font-bomber mt-4 hover:brightness-110 active:scale-95 transition-all">
        Создать аккаунт
      </button>
    </div>
  </div>
</template>
