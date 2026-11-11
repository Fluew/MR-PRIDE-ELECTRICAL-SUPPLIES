/** Inbound P.O. deliveries (Deliveries page → Inbound): shapes sent by InboundReceiving / InboundDeliveryController. */

export interface ReceivableLine {
    id: number;
    quantity: number;
    item_sku: string | null;
    description: string;
    unit_cost: number | null;
    unit_of_measure: string | null;
    extended_cost: number | null;
    previously_received: number;
    remaining: number;
    product: { id: number; sku: string; name: string; via?: string } | null;
}
export interface ReceivableOrder {
    po_number: string;
    po_date: string | null;
    supplier: { id: number; name: string } | null;
    status: string;
    lines: ReceivableLine[];
}
export interface DeliveryDetailItem {
    id: number;
    purchase_order_id: number | null;
    item_sku: string | null;
    description: string;
    ordered_quantity: number | null;
    previously_received: number | null;
    remaining: number | null;
    received_quantity: number;
    accepted_quantity: number | null;
    rejected_quantity: number | null;
    unit_cost: number | null;
    unit_of_measure: string | null;
    extended_cost: number | null;
    quality_status: string;
    inspection_remarks: string | null;
    rejection_category: string | null;
    over_receipt_reason: string | null;
    status: string;
    product: { id: number; sku: string; name: string } | null;
}
export interface DeliveryDetail {
    id: number;
    delivery_number: string;
    delivery_date: string;
    purchase_order_number: string | null;
    order_date: string | null;
    supplier: string | null;
    supplier_document_number: string | null;
    status: string;
    items: DeliveryDetailItem[];
}

export interface DeliveryFull extends DeliveryDetail {
    notes: string | null;
    confirmed_by: string | null;
    confirmed_at: string | null;
    cancelled_by: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    can_delete: boolean;
    items: (DeliveryDetailItem & { inspected_on: string | null; inspected_by: string | null; posted_quantity: number; posted_at: string | null; resolution_status: string | null; can_post: boolean })[];
    history: { id: number; event: string; description: string; by: string; at: string | null; reason: string | null;
        changes: { delivery_date?: { from: string; to: string }; supplier_document_number?: { from: string | null; to: string | null };
            lines?: { product: string; from: Record<string, number | string | null>; to: Record<string, number | string | null> }[] } | null }[];
}
