#!/usr/bin/env python3
"""Insert default bar catalog into products (same rows as ProductSeeder)."""

from __future__ import annotations

from pathlib import Path

CATALOG = [
    ("Вода 0.5", "Напитки", 80, 40),
    ("Кола 0.5", "Напитки", 120, 30),
    ("Спрайт 0.5", "Напитки", 120, 24),
    ("Энергетик", "Напитки", 150, 24),
    ("Сок яблоко 0.2", "Напитки", 90, 16),
    ("Чипсы", "Снэки", 130, 20),
    ("Сухарики", "Снэки", 90, 20),
    ("Snickers", "Снэки", 90, 30),
    ("Twix", "Снэки", 90, 24),
    ("Орешки", "Снэки", 110, 16),
    ("Доширак", "Еда", 100, 20),
    ("Хот-дог", "Еда", 180, 10),
]


def load_env(path: Path) -> dict[str, str]:
    keys: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        s = line.strip()
        if not s or s.startswith("#") or "=" not in s:
            continue
        k, v = s.split("=", 1)
        keys[k.strip()] = v.strip().strip('"').strip("'")
    return keys


def main() -> int:
    root = Path(__file__).resolve().parents[1]
    env = load_env(root / ".env")
    conn_name = env.get("DB_CONNECTION", "")
    host = env.get("DB_HOST", "127.0.0.1")
    port = int(env.get("DB_PORT") or "5432")
    name = env.get("DB_DATABASE", "")
    user = env.get("DB_USERNAME", "")
    password = env.get("DB_PASSWORD", "")
    dry_run = "--dry-run" in __import__("sys").argv

    print(f"APP_URL={env.get('APP_URL', '')}")
    print(f"DB_CONNECTION={conn_name} host={host} port={port} db={name} user={user}")
    if dry_run:
        print("mode=dry-run")

    if conn_name not in ("pgsql", "postgres", "postgresql"):
        print(f"unsupported DB_CONNECTION={conn_name}")
        return 1

    try:
        import pg8000.dbapi as pg
    except ImportError:
        print("pg8000 missing")
        return 2

    conn = pg.connect(
        host=host,
        port=port,
        database=name,
        user=user,
        password=password,
    )
    conn.autocommit = True
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*) FROM products")
    before = int(cur.fetchone()[0])
    print(f"products_before={before}")
    if dry_run:
        cur.close()
        conn.close()
        return 0

    upserted = 0
    for product_name, category, price, stock in CATALOG:
        cur.execute("SELECT id FROM products WHERE name = %s LIMIT 1", (product_name,))
        row = cur.fetchone()
        if row:
            cur.execute(
                """
                UPDATE products
                SET category = %s, price = %s, stock = %s, is_active = TRUE, updated_at = NOW()
                WHERE id = %s
                """,
                (category, price, stock, row[0]),
            )
        else:
            cur.execute(
                """
                INSERT INTO products (name, category, price, stock, image, is_active, created_at, updated_at)
                VALUES (%s, %s, %s, %s, '', TRUE, NOW(), NOW())
                """,
                (product_name, category, price, stock),
            )
        upserted += 1

    cur.execute("SELECT COUNT(*) FROM products")
    after = int(cur.fetchone()[0])
    print(f"upserted={upserted} products_after={after}")
    cur.close()
    conn.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
