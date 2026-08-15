<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const clubName = computed(() => (page.props as any).club?.name || (page.props as any).club_name || 'Клуб')
</script>

<template>
    <Head :title="`Публичная оферта · ${clubName}`" />
    <div class="min-h-screen bg-[#050505] text-white font-mono selection:bg-[#22c55e] selection:text-black">
        <div class="max-w-3xl mx-auto px-6 py-16 space-y-10">
            <div>
                <Link href="/" class="text-[10px] uppercase tracking-[0.35em] text-white/30 hover:text-[#22c55e] font-black italic">
                    ← На главную
                </Link>
                <h1 class="mt-6 text-4xl font-black uppercase italic tracking-tighter">
                    Публичная <span class="text-[#22c55e]">оферта</span>
                </h1>
                <p class="mt-3 text-white/30 text-[10px] uppercase tracking-[0.3em] font-black italic">
                    Автоматические расчёты · электронный кассовый чек
                </p>
            </div>

            <article class="space-y-6 text-sm leading-relaxed text-white/70 bg-[#0a0a0a] border border-white/5 rounded-[1.25rem] p-8 md:p-12">
                <p>
                    Настоящий документ является официальным предложением (публичной офертой)
                    клуба {{ clubName }} заключить договор на оказание услуг и продажу товаров
                    через автоматические устройства и электронные сервисы (сайт, терминал,
                    мобильное приложение, шелл на ПК).
                </p>

                <h2 class="text-[#22c55e] text-xs font-black uppercase tracking-[0.25em] pt-4">Электронный кассовый чек</h2>
                <p class="border border-[#22c55e]/20 bg-[#22c55e]/5 rounded-2xl p-5 text-white/85">
                    Расчеты осуществляются с использованием автоматических устройств и электронных сервисов.
                    Покупатель соглашается на получение кассового чека исключительно в электронном виде
                    путем демонстрации QR-кода на экране устройства и/или сохранения QR-кода в личном кабинете.
                    Отправка чека на SMS/Email осуществляется только при выборе соответствующей опции
                    при оформлении платежа.
                </p>

                <h2 class="text-[#22c55e] text-xs font-black uppercase tracking-[0.25em] pt-4">Акцепт</h2>
                <p>
                    Регистрация профиля, вход в шелл/приложение, нажатие кнопки «Оплатить»
                    и иные действия по использованию сервисов означают полное согласие
                    с условиями настоящей оферты.
                </p>

                <h2 class="text-[#22c55e] text-xs font-black uppercase tracking-[0.25em] pt-4">Кошелёк и фискализация</h2>
                <p>
                    Пополнение баланса фиксируется как аванс. Списание за бронь или товары магазина —
                    как полный расчёт с зачётом ранее внесённого аванса. Бумажный чек по умолчанию
                    не печатается; по запросу администратор может выдать печатную копию
                    ранее сформированного электронного чека без повторной фискализации.
                </p>
            </article>
        </div>
    </div>
</template>
