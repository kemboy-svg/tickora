import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../models.dart';
import '../theme.dart';

/// Tickets full screen for the door, one per page; swipe between them.
class TicketScreen extends StatefulWidget {
  const TicketScreen({super.key, required this.event, required this.tickets, this.initialIndex = 0});

  final TicketEvent event;
  final List<Ticket> tickets;
  final int initialIndex;

  @override
  State<TicketScreen> createState() => _TicketScreenState();
}

class _TicketScreenState extends State<TicketScreen> {
  late final PageController _pages = PageController(initialPage: widget.initialIndex);
  late int _index = widget.initialIndex;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final count = widget.tickets.length;
    return Scaffold(
      backgroundColor: Brand.violet,
      appBar: AppBar(
        backgroundColor: Brand.violet,
        foregroundColor: Colors.white,
        title: Text(count > 1 ? 'Ticket ${_index + 1} of $count' : 'Your ticket',
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: PageView.builder(
          controller: _pages,
          itemCount: count,
          onPageChanged: (i) => setState(() => _index = i),
          itemBuilder: (context, i) => _TicketCard(event: widget.event, ticket: widget.tickets[i]),
        ),
      ),
    );
  }
}

class _TicketCard extends StatelessWidget {
  const _TicketCard({required this.event, required this.ticket});

  final TicketEvent event;
  final Ticket ticket;

  @override
  Widget build(BuildContext context) {
    final qr = ticket.qr;
    const ink = TextStyle(color: Brand.ink);

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      child: Container(
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24)),
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Text(event.title,
                textAlign: TextAlign.center,
                style: ink.copyWith(fontSize: 22, fontWeight: FontWeight.w900)),
            const SizedBox(height: 6),
            Text(event.dateLabel, textAlign: TextAlign.center, style: ink.copyWith(fontWeight: FontWeight.w700)),
            if (event.venue != null)
              Text(event.address ?? event.venue!, textAlign: TextAlign.center, style: ink.copyWith(color: Colors.black54)),
            const SizedBox(height: 20),
            Stack(
              alignment: Alignment.center,
              children: [
                if (qr != null)
                  Opacity(
                    opacity: ticket.usable ? 1 : 0.15,
                    child: QrImageView(
                      data: qr,
                      size: 260,
                      backgroundColor: Colors.white,
                    ),
                  )
                else
                  const SizedBox(
                    height: 260,
                    child: Center(
                      child: Text('The QR code is not available for this ticket.',
                          textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
                    ),
                  ),
                if (!ticket.usable)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    decoration: BoxDecoration(color: Brand.coral, borderRadius: BorderRadius.circular(20)),
                    child: Text(ticket.statusLabel.toUpperCase(),
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, letterSpacing: 1)),
                  ),
              ],
            ),
            const SizedBox(height: 16),
            const _Notch(),
            const SizedBox(height: 16),
            _Field(label: 'Ticket', value: ticket.type),
            if (ticket.attendee != null) _Field(label: 'Name', value: ticket.attendee!),
            _Field(label: 'Reference', value: ticket.reference),
            const SizedBox(height: 12),
            Text(
              ticket.usable
                  ? 'Turn your screen brightness up at the entrance. This ticket works offline.'
                  : 'This ticket can no longer be used for entry.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.black54, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}

/// The perforation between the QR code and the details.
class _Notch extends StatelessWidget {
  const _Notch();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: List.generate(
        24,
        (i) => Expanded(
          child: Container(
            height: 2,
            margin: const EdgeInsets.symmetric(horizontal: 3),
            color: i.isEven ? const Color(0xFFDDD6FF) : Colors.transparent,
          ),
        ),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Text(label, style: const TextStyle(color: Colors.black54)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(value,
                textAlign: TextAlign.right,
                style: const TextStyle(color: Brand.ink, fontWeight: FontWeight.w800)),
          ),
        ],
      ),
    );
  }
}
