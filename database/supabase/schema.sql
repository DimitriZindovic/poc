begin;

create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create table if not exists public.shopify_orders (
  id bigserial primary key,
  shopify_order_id text not null unique,
  email text,
  customer_name text,
  shipping_address jsonb,
  financial_status text,
  raw_payload jsonb not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

drop trigger if exists trg_shopify_orders_updated_at on public.shopify_orders;
create trigger trg_shopify_orders_updated_at
before update on public.shopify_orders
for each row execute function public.set_updated_at();

create index if not exists idx_shopify_orders_shopify_order_id on public.shopify_orders (shopify_order_id);

create table if not exists public.subscriptions (
  id bigserial primary key,
  shopify_order_ref_id bigint not null references public.shopify_orders(id) on delete cascade,
  shopify_customer_id text,
  email text,
  product_title text not null,
  total_boxes integer not null default 6 check (total_boxes > 0),
  shipped_boxes integer not null default 0 check (shipped_boxes >= 0),
  status text not null default 'active' check (status in ('active', 'paused', 'cancelled', 'completed')),
  shipping_address jsonb,
  next_shipment_at date not null,
  last_checked_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  check (shipped_boxes <= total_boxes)
);

drop trigger if exists trg_subscriptions_updated_at on public.subscriptions;
create trigger trg_subscriptions_updated_at
before update on public.subscriptions
for each row execute function public.set_updated_at();

create index if not exists idx_subscriptions_status_next_shipment on public.subscriptions (status, next_shipment_at);
create index if not exists idx_subscriptions_order_ref on public.subscriptions (shopify_order_ref_id);

create table if not exists public.shipping_lists (
  id bigserial primary key,
  run_date date not null,
  items_count integer not null default 0 check (items_count >= 0),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

drop trigger if exists trg_shipping_lists_updated_at on public.shipping_lists;
create trigger trg_shipping_lists_updated_at
before update on public.shipping_lists
for each row execute function public.set_updated_at();

create index if not exists idx_shipping_lists_run_date on public.shipping_lists (run_date);

create table if not exists public.shipping_list_items (
  id bigserial primary key,
  shipping_list_id bigint not null references public.shipping_lists(id) on delete cascade,
  subscription_id bigint not null references public.subscriptions(id) on delete cascade,
  box_number integer not null check (box_number > 0),
  status text not null default 'pending' check (status in ('pending', 'prepared', 'shipped', 'skipped', 'failed')),
  skip_reason text,
  shipping_snapshot jsonb,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint shipping_item_unique unique (shipping_list_id, subscription_id, box_number)
);

drop trigger if exists trg_shipping_list_items_updated_at on public.shipping_list_items;
create trigger trg_shipping_list_items_updated_at
before update on public.shipping_list_items
for each row execute function public.set_updated_at();

create index if not exists idx_shipping_list_items_list_id on public.shipping_list_items (shipping_list_id);
create index if not exists idx_shipping_list_items_subscription_id on public.shipping_list_items (subscription_id);
create index if not exists idx_shipping_list_items_status on public.shipping_list_items (status);

commit;
