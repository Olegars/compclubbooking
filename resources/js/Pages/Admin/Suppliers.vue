<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    suppliers: Array<Record<string, any>>
    debt: {
        debt_total: number
        overdue_total: number
        invoices: Array<Record<string, any>>
    }
    margins: Array<Record<string, any>>
}>()

const tab = ref<'suppliers' | 'debt' | 'margin'>('suppliers')

const form = useForm({
    name: '',
    inn: '',
    phone: '',
    email: '',
    payment_terms_days: 14,
    notes: '',
    is_active: true,
})

const invoiceForm = useForm({
    supplier_id: null as number | null,
    number: '',
    issued_at: new Date().toISOString().slice(0, 10),
    due_at: '',
    total_amount: 0,
    notes: '',
})

const payAmount = ref<Record<number, number>>({})
const payNote = ref<Record<number, string>>({})

const activeSuppliers = computed(() => props.suppliers.filter(s => s.is_active))

const submitSupplier = () => {
    form.post('/admin/suppliers', {
        onSuccess: () => form.reset('name', 'inn', 'phone', 'email', 'notes'),
    })
}

const toggleActive = (s: Record<string, any>) => {
    router.put(`/admin/suppliers/${s.id}`, {
        name: s.name,
        inn: s.inn,
        phone: s.phone,
        email: s.email,
        payment_terms_days: s.payment_terms_days,
        notes: s.notes,
        is_active: !s.is_active,
    })
}

const submitInvoice = () => {
    invoiceForm.post('/admin/suppliers/invoices', {
        onSuccess: () => {
            invoiceForm.reset('number', 'notes', 'total_amount')
            invoiceForm.issued_at = new Date().toISOString().slice(0, 10)
        },
    })
}

const payInvoice = (id: number) => {
    const amount = Number(payAmount.value[id] || 0)
    if (amount <= 0) {
        alert('Укажите сумму оплаты')
        return
    }
    router.post(`/admin/suppliers/invoices/${id}/pay`, {
        amount,
        note: payNote.value[id] || undefined,
    }, {
        onSuccess: () => {
            payAmount.value[id] = 0
            payNote.value[id] = ''
        },
    })
}

const fmt = (n: number) => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
</script>

<template>
    <Head title="Поставщики" />
    <AdminLayout>
        <div class="max-w-6xl mx-auto space-y-8 font-mono pb-20 text-white">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem]">
                <h1 class="text-4xl font-black uppercase italic text-emerald-400 tracking-tighter">
                    Поставщики <span class="text-white">и себестоимость</span>
                </h1>
                <p class="text-white/30 text-[10px] uppercase tracking-[0.3em] mt-3 font-black">
                    Долги · отсрочки · маржа · без Excel
                </p>

                <div class="flex flex-wrap gap-2 mt-6">
                    <button type="button" @click="tab = 'suppliers'"
                            class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border cursor-pointer"
                            :class="tab === 'suppliers' ? 'bg-emerald-500 text-black border-emerald-500' : 'border-white/10 text-white/40'">
                        Карточки
                    </button>
                    <button type="button" @click="tab = 'debt'"
                            class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border cursor-pointer"
                            :class="tab === 'debt' ? 'bg-amber-500 text-black border-amber-500' : 'border-white/10 text-white/40'">
                        Долги {{ fmt(debt.debt_total) }}₽
                        <span v-if="debt.overdue_total > 0" class="ml-1 text-red-700">· просрочка {{ fmt(debt.overdue_total) }}₽</span>
                    </button>
                    <button type="button" @click="tab = 'margin'"
                            class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border cursor-pointer"
                            :class="tab === 'margin' ? 'bg-cyan-500 text-black border-cyan-500' : 'border-white/10 text-white/40'">
                        Маржа каталога
                    </button>
                </div>
            </div>

            <div v-if="tab === 'suppliers'" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <form @submit.prevent="submitSupplier" class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 space-y-4 h-fit">
                    <h2 class="text-lg font-black uppercase italic text-emerald-400">Новый поставщик</h2>
                    <input v-model="form.name" required placeholder="Название" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-emerald-500" />
                    <input v-model="form.inn" placeholder="ИНН" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <input v-model="form.phone" placeholder="Телефон" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <input v-model="form.email" type="email" placeholder="Email" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <div>
                        <label class="text-[10px] text-white/30 uppercase font-black mb-1 block">Отсрочка, дней</label>
                        <input v-model.number="form.payment_terms_days" type="number" min="0" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    </div>
                    <textarea v-model="form.notes" rows="2" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <button type="submit" class="w-full py-4 bg-emerald-500 text-black font-black uppercase text-[10px] tracking-widest rounded-xl cursor-pointer">Добавить</button>
                </form>

                <div class="lg:col-span-2 space-y-4">
                    <div v-for="s in suppliers" :key="s.id"
                         class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-5 flex flex-wrap items-start justify-between gap-4"
                         :class="!s.is_active ? 'opacity-50' : ''">
                        <div>
                            <div class="text-xl font-black uppercase italic">{{ s.name }}</div>
                            <div class="text-[10px] text-white/30 mt-1 uppercase tracking-widest">
                                отсрочка {{ s.payment_terms_days || 0 }} дн
                                <span v-if="s.inn"> · ИНН {{ s.inn }}</span>
                                <span v-if="s.phone"> · {{ s.phone }}</span>
                            </div>
                            <div v-if="s.notes" class="text-xs text-white/40 mt-2">{{ s.notes }}</div>
                        </div>
                        <button type="button" @click="toggleActive(s)"
                                class="px-4 py-2 rounded-xl text-[10px] font-black uppercase border cursor-pointer"
                                :class="s.is_active ? 'border-white/10 text-white/40' : 'border-emerald-500/40 text-emerald-400'">
                            {{ s.is_active ? 'Выключить' : 'Включить' }}
                        </button>
                    </div>
                    <div v-if="!suppliers.length" class="p-10 border border-dashed border-white/10 rounded-3xl text-center text-white/30 text-sm uppercase font-black">
                        Поставщиков пока нет
                    </div>
                </div>
            </div>

            <div v-else-if="tab === 'debt'" class="space-y-6">
                <form @submit.prevent="submitInvoice" class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <h2 class="md:col-span-3 text-lg font-black uppercase italic text-amber-400">Ручной счёт / накладная</h2>
                    <select v-model.number="invoiceForm.supplier_id" required class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none">
                        <option :value="null">Поставщик</option>
                        <option v-for="s in activeSuppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <input v-model="invoiceForm.number" placeholder="№ счёта" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <input v-model.number="invoiceForm.total_amount" type="number" step="0.01" min="0.01" required placeholder="Сумма" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <input v-model="invoiceForm.issued_at" type="date" required class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <input v-model="invoiceForm.due_at" type="date" placeholder="Срок" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <input v-model="invoiceForm.notes" placeholder="Комментарий" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none" />
                    <button type="submit" class="md:col-span-3 py-4 bg-amber-500 text-black font-black uppercase text-[10px] tracking-widest rounded-xl cursor-pointer">Создать счёт</button>
                </form>

                <div class="space-y-3">
                    <div v-for="inv in debt.invoices" :key="inv.id"
                         class="bg-[#0a0a0a] border rounded-[1rem] p-5"
                         :class="inv.overdue ? 'border-red-500/40' : 'border-white/5'">
                        <div class="flex flex-wrap justify-between gap-4">
                            <div>
                                <div class="font-black uppercase italic">{{ inv.supplier }} · {{ inv.number || ('#' + inv.id) }}</div>
                                <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                                    выставлен {{ inv.issued_at }} · срок {{ inv.due_at || '—' }}
                                    <span v-if="inv.overdue" class="text-red-400"> · просрочен</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl font-black text-amber-400">{{ fmt(inv.balance) }}₽</div>
                                <div class="text-[10px] text-white/30">из {{ fmt(inv.total) }}₽ · оплачено {{ fmt(inv.paid) }}₽</div>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-3 items-end">
                            <div>
                                <label class="text-[9px] text-white/30 uppercase font-black block mb-1">Оплатить</label>
                                <input v-model.number="payAmount[inv.id]" type="number" step="0.01" min="0.01" :max="inv.balance"
                                       class="w-36 bg-black border border-white/10 rounded-xl px-3 py-2 text-sm outline-none" />
                            </div>
                            <input v-model="payNote[inv.id]" placeholder="Заметка" class="flex-1 min-w-[140px] bg-black border border-white/10 rounded-xl px-3 py-2 text-sm outline-none" />
                            <button type="button" @click="payInvoice(inv.id)"
                                    class="px-5 py-2 bg-emerald-500 text-black text-[10px] font-black uppercase rounded-xl cursor-pointer">
                                Внести
                            </button>
                        </div>
                    </div>
                    <div v-if="!debt.invoices.length" class="p-10 border border-dashed border-white/10 rounded-3xl text-center text-white/30 text-sm uppercase font-black">
                        Открытых долгов нет
                    </div>
                </div>
            </div>

            <div v-else class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="text-[10px] uppercase tracking-widest text-white/30 border-b border-white/5">
                        <tr>
                            <th class="px-5 py-4">Товар</th>
                            <th class="px-5 py-4">Цена</th>
                            <th class="px-5 py-4">Себест.</th>
                            <th class="px-5 py-4">Маржа</th>
                            <th class="px-5 py-4">%</th>
                            <th class="px-5 py-4">Остаток</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in margins" :key="m.id" class="border-b border-white/5 hover:bg-white/[0.02]">
                            <td class="px-5 py-3 font-bold uppercase italic">{{ m.name }}</td>
                            <td class="px-5 py-3">{{ fmt(m.price) }}₽</td>
                            <td class="px-5 py-3 text-white/50">{{ fmt(m.cost) }}₽</td>
                            <td class="px-5 py-3" :class="m.margin >= 0 ? 'text-emerald-400' : 'text-red-400'">{{ fmt(m.margin) }}₽</td>
                            <td class="px-5 py-3 text-cyan-400">{{ m.margin_pct }}%</td>
                            <td class="px-5 py-3">{{ m.stock }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
