(() => {
    const root = document.querySelector('[data-order-events-url]');
    if (!(root instanceof HTMLElement)) return;

    const connectionStatus = root.querySelector('[data-order-events-status]');
    if (!('EventSource' in window)) {
        if (connectionStatus instanceof HTMLElement) {
            connectionStatus.textContent = 'Hãy tải lại trang để xem trạng thái mới nhất.';
        }
        return;
    }
    const orderBadge = document.getElementById('realtimeOrderStatus');
    const paymentBadge = document.getElementById('realtimePaymentStatus');
    const deliveryBadge = document.getElementById('realtimeDeliveryStatus');
    const orderHistory = root.querySelector('[data-order-history]');
    const orderHistoryEmpty = root.querySelector('[data-order-history-empty]');
    const deliveryFacts = root.querySelector('[data-delivery-facts]');
    const deliveryEmpty = root.querySelector('[data-delivery-empty]');
    const deliveryHistory = root.querySelector('[data-delivery-history]');
    const deliveryHistoryEmpty = root.querySelector('[data-delivery-history-empty]');
    const receiveConfirmation = root.querySelector('[data-receive-confirm]');

    const orderLabels = {
        pending: 'Chờ xác nhận', confirmed: 'Đã xác nhận', packing: 'Đang đóng gói',
        processing: 'Đang xử lý', shipping: 'Đang giao', delivered: 'Đã giao',
        cancelled: 'Đã hủy', returned: 'Đã hoàn trả',
    };
    const paymentLabels = {
        paid: 'Đã thanh toán', unpaid: 'Chưa thanh toán', pending: 'Đang chờ thanh toán',
        awaiting_verification: 'Chờ xác minh', failed: 'Thanh toán thất bại', refunded: 'Đã hoàn tiền',
        partially_refunded: 'Đã hoàn tiền một phần',
    };
    const deliveryLabels = {
        created: 'Đã tạo vận đơn', picked_up: 'Đã lấy hàng', in_transit: 'Đang vận chuyển',
        out_for_delivery: 'Đang giao tới khách', failed_delivery: 'Giao hàng thất bại',
        delivered: 'Giao thành công',
    };
    const statusClasses = new Set(Object.keys(orderLabels));
    const deliveryClasses = new Set(Object.keys(deliveryLabels));
    const paymentClasses = new Set(['paid', 'unpaid']);

    const formatDateTime = (value) => {
        if (!value) return 'Thời điểm chưa được ghi nhận';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('vi-VN');
    };
    const formatDate = (value) => {
        if (!value) return 'Chưa có thông tin';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('vi-VN');
    };
    const updateBadge = (element, prefix, value, labels, safeClasses) => {
        if (!(element instanceof HTMLElement)) return;
        const key = typeof value === 'string' ? value : '';
        element.className = `order-status${safeClasses.has(key) ? ` ${prefix}-${key}` : ''}`;
        element.textContent = labels[key] || 'Đang cập nhật';
        element.hidden = key === '';
    };
    const renderTimeline = (list, emptyState, events, labels, dateField, isDelivery = false) => {
        if (!(list instanceof HTMLOListElement)) return;
        const rows = Array.isArray(events) ? events : [];
        list.replaceChildren();
        for (const event of rows) {
            if (!event || typeof event !== 'object') continue;
            const status = typeof event.status === 'string' ? event.status : '';
            const item = document.createElement('li');
            if (isDelivery && status === 'failed_delivery') item.classList.add('is-failed');
            const heading = document.createElement('strong');
            heading.textContent = `${isDelivery && status === 'failed_delivery' ? '⚠ ' : '✓ '}${labels[status] || (isDelivery ? 'Cập nhật giao hàng' : 'Cập nhật trạng thái')}`;
            const time = document.createElement('span');
            time.textContent = formatDateTime(event[dateField]);
            item.append(heading, time);
            if (isDelivery && typeof event.note === 'string' && event.note !== '') {
                const note = document.createElement('span');
                note.className = 'delivery-event-note';
                note.textContent = event.note;
                item.append(note);
            }
            list.append(item);
        }
        list.hidden = list.children.length === 0;
        if (emptyState instanceof HTMLElement) emptyState.hidden = list.children.length !== 0;
    };

    const applySnapshot = (snapshot) => {
        if (!snapshot || snapshot.order_id !== root.dataset.orderId) return;
        updateBadge(orderBadge, 'order-status', snapshot.status, orderLabels, statusClasses);
        updateBadge(paymentBadge, 'payment-status', snapshot.payment_status, paymentLabels, paymentClasses);
        if (receiveConfirmation instanceof HTMLFormElement) {
            receiveConfirmation.hidden = snapshot.status !== 'shipping';
        }
        renderTimeline(orderHistory, orderHistoryEmpty, snapshot.status_history, orderLabels, 'at');

        const delivery = snapshot.delivery && typeof snapshot.delivery === 'object' ? snapshot.delivery : null;
        updateBadge(deliveryBadge, 'delivery-status', delivery?.status, deliveryLabels, deliveryClasses);
        if (deliveryFacts instanceof HTMLElement) {
            const hasFacts = Boolean(delivery && (delivery.tracking_code || delivery.estimated_delivery_date || delivery.delivered_at || delivery.status));
            deliveryFacts.hidden = !hasFacts;
            if (deliveryEmpty instanceof HTMLElement) deliveryEmpty.hidden = hasFacts;
            const tracking = document.getElementById('realtimeTrackingCode');
            const estimated = document.getElementById('realtimeEstimatedDate');
            const deliveredFact = root.querySelector('[data-delivered-at-fact]');
            const delivered = document.getElementById('realtimeDeliveredAt');
            if (tracking instanceof HTMLElement) tracking.textContent = delivery?.tracking_code || 'Chưa có mã vận đơn';
            if (estimated instanceof HTMLElement) estimated.textContent = formatDate(delivery?.estimated_delivery_date);
            if (delivered instanceof HTMLElement) delivered.textContent = formatDateTime(delivery?.delivered_at);
            if (deliveredFact instanceof HTMLElement) deliveredFact.hidden = !delivery?.delivered_at;
        }
        renderTimeline(deliveryHistory, deliveryHistoryEmpty, delivery?.history, deliveryLabels, 'at', true);
    };

    const events = new EventSource(root.dataset.orderEventsUrl, { withCredentials: true });
    events.onopen = () => {
        if (connectionStatus instanceof HTMLElement) connectionStatus.textContent = 'Đang theo dõi cập nhật…';
    };
    events.addEventListener('order_updated', (event) => {
        try {
            applySnapshot(JSON.parse(event.data));
            if (connectionStatus instanceof HTMLElement) connectionStatus.textContent = 'Đang theo dõi cập nhật…';
        } catch {
            if (connectionStatus instanceof HTMLElement) connectionStatus.textContent = 'Đang kết nối lại…';
        }
    });
    events.addEventListener('heartbeat', () => {
        if (connectionStatus instanceof HTMLElement) connectionStatus.textContent = 'Đang theo dõi cập nhật…';
    });
    events.addEventListener('reconnecting', () => {
        if (connectionStatus instanceof HTMLElement) connectionStatus.textContent = 'Đang kết nối lại…';
    });
    events.onerror = () => {
        // The server closes each bounded long-poll response intentionally; EventSource reconnects automatically.
        if (connectionStatus instanceof HTMLElement) connectionStatus.textContent = 'Đang chờ lần đồng bộ kế tiếp…';
        if (events.readyState === EventSource.CLOSED) events.close();
    };
})();
