# FACEIT egress на RB5009. Основной default и masquerade клуба не трогать.
# Режим whitelist: тикет Cyber Cafe подтверждён, один белый из тикета.
# Режим pool: тикета нет или пул забанен. Другой префикс, не та же подсеть.
# ether2 LTE — failover клуба, не постоянный FACEIT-egress.
# Пока нет ни whitelist, ни живого pool — фича faceit в админке off.

# Места VLAN 20. Касса, .10, .47 и VLAN 40 сюда не входят.
/ip firewall address-list
add list=faceit-seats address=192.168.20.100 comment="PC seat"
add list=faceit-seats address=192.168.20.101 comment="PC seat"

# Mangle раньше FastTrack. Ключ — LAN IP места, не per-packet.
# Пример пула из двух белых другого префикса.
/ip firewall mangle
add chain=prerouting src-address-list=faceit-seats action=mark-connection \
    new-connection-mark=faceit-conn passthrough=yes comment="faceit seats"
add chain=prerouting connection-mark=faceit-conn \
    per-connection-classifier=src-address:2/0 action=mark-connection \
    new-connection-mark=faceit-w0 passthrough=yes
add chain=prerouting connection-mark=faceit-conn \
    per-connection-classifier=src-address:2/1 action=mark-connection \
    new-connection-mark=faceit-w1 passthrough=yes
add chain=prerouting connection-mark=faceit-w0 action=mark-routing \
    new-routing-mark=faceit-w0 passthrough=no
add chain=prerouting connection-mark=faceit-w1 action=mark-routing \
    new-routing-mark=faceit-w1 passthrough=no

/routing table
add name=faceit-w0 fib
add name=faceit-w1 fib

# Свои маршруты только у этих таблиц. Default main не менять.
/ip route
add dst-address=0.0.0.0/0 gateway=WAN-GW routing-table=faceit-w0
add dst-address=0.0.0.0/0 gateway=WAN-GW routing-table=faceit-w1

# Отдельный src-nat на каждый routing-mark. На этот трафик masquerade не ставить.
/ip firewall nat
add chain=srcnat routing-mark=faceit-w0 action=src-nat to-addresses=203.0.113.10
add chain=srcnat routing-mark=faceit-w1 action=src-nat to-addresses=203.0.113.11

# FastTrack не должен брать connection-mark faceit*.
# Бан одного белого: выключить только его src-nat. Connection-mark соседей не сбрасывать.
# Бан всего пула: один mangle faceit-seats в таблицу faceit-alt (другой канал).
