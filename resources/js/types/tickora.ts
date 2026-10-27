// Shapes of the props Tickora's pages receive from the server presenters.

export type Money = {
    amount_minor: number;
    currency: string;
    decimal: string;
    formatted: string;
};

export type Availability = 'available' | 'few_left' | 'sold_out';

export type EventCard = {
    id: string;
    slug: string;
    title: string;
    summary: string | null;
    category: string;
    category_slug: string;
    starts_at: string;
    starts_label: string | null;
    venue: string | null;
    city: string | null;
    country_code: string | null;
    price_from: Money | null;
    is_free: boolean;
    availability: Availability;
};

export type PublicTicketType = {
    id: string;
    name: string;
    description: string | null;
    price: Money;
    is_free: boolean;
    remaining: number;
    sold_out: boolean;
    on_sale: boolean;
    sales_end_label: string | null;
    min_per_order: number;
    max_per_order: number;
};

export type EventDetail = EventCard & {
    description: string | null;
    timezone: string;
    timezone_label: string;
    date_label: string | null;
    time_label: string;
    ends_at: string;
    sales_end_label: string | null;
    sales_open: boolean;
    refund_policy: string | null;
    min_age: number | null;
    status: EventStatus;
    is_past: boolean;
    venue_detail: {
        name: string;
        address_line: string;
        city: string;
        region: string | null;
        country_code: string;
    } | null;
    organizer: { name: string; slug: string; verified: boolean };
    ticket_types: PublicTicketType[];
};

export type EventStatus =
    | 'draft'
    | 'pending_review'
    | 'published'
    | 'cancelled'
    | 'completed';

export type TicketTypeStatus = 'active' | 'paused' | 'hidden';

export type OrganizerStatus = 'pending' | 'verified' | 'suspended' | 'rejected';

export type OrganizerEventRow = {
    id: string;
    slug: string;
    title: string;
    status: EventStatus;
    visibility: 'public' | 'unlisted';
    starts_label: string | null;
    venue: string | null;
    city: string | null;
    ticket_types: number;
    sold: number;
    capacity: number;
    review_notes: string | null;
};

export type TicketTypeFormValues = {
    id: string;
    name: string;
    description: string | null;
    price: string;
    price_label: string;
    quantity_total: number;
    quantity_sold: number;
    min_per_order: number;
    max_per_order: number;
    sales_starts_at: string | null;
    sales_ends_at: string | null;
    status: TicketTypeStatus;
    has_sales: boolean;
};

export type VenueFormValues = {
    name: string;
    address_line: string;
    city: string;
    region: string | null;
    postal_code: string | null;
    country_code: string;
};

export type EventFormValues = {
    id: string;
    slug: string;
    status: EventStatus;
    is_draft: boolean;
    title: string;
    category: string;
    summary: string | null;
    description: string | null;
    timezone: string;
    currency: string;
    starts_at: string | null;
    ends_at: string | null;
    sales_starts_at: string | null;
    sales_ends_at: string | null;
    visibility: 'public' | 'unlisted';
    refund_policy: string | null;
    min_age: number | null;
    review_notes: string | null;
    cancellation_reason: string | null;
    venue: VenueFormValues;
    ticket_types: TicketTypeFormValues[];
};

export type Category = { name: string; slug: string };

export type EventFormOptions = {
    categories: Category[];
    currencies: string[];
    visibilities: Array<'public' | 'unlisted'>;
    timezones: string[];
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    links: PaginationLink[];
};

export type Workspace = {
    current: {
        id: string;
        name: string;
        status: OrganizerStatus;
        can_sell: boolean;
        role: 'owner' | 'manager' | 'staff' | null;
    };
    available: Array<{ id: string; name: string }>;
};

export type NavFlags = {
    organizer: boolean;
    moderation: boolean;
    verification: boolean;
};

export type CheckoutStatus =
    | 'open'
    | 'awaiting_payment'
    | 'completed'
    | 'expired'
    | 'cancelled';

export type CheckoutLine = {
    name: string | null;
    quantity: number;
    unit_price: Money;
    subtotal: Money;
    discount: Money;
};

export type CheckoutSummary = {
    id: string;
    status: CheckoutStatus;
    expires_at: string;
    seconds_left: number;
    event: {
        title: string;
        slug: string;
        date_label: string | null;
        venue: string | null;
        city: string | null;
    };
    lines: CheckoutLine[];
    subtotal: Money;
    discount: Money;
    fee: Money;
    tax: Money;
    total: Money;
    promo_code: string | null;
    order: { reference: string; status: string; status_label: string } | null;
};

export type PromoCodeRow = {
    code: string;
    discount_label: string;
    event: string | null;
    starts_at: string | null;
    ends_at: string | null;
    redemptions: number;
    max_redemptions: number | null;
    max_per_customer: number | null;
    is_active: boolean;
};
