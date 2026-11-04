import 'package:flutter/material.dart';

import '../theme.dart';

/// The ticket ring: a violet tile, a white ring notched on both sides like
/// a ticket, and a coral centre.
class TickoraMark extends StatelessWidget {
  const TickoraMark({super.key, this.size = 40});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(dimension: size, child: CustomPaint(painter: _MarkPainter()));
  }
}

class _MarkPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final s = size.width / 64;
    canvas.scale(s);

    final violet = Paint()..color = Brand.violet;
    canvas.drawRRect(RRect.fromLTRBR(0, 0, 64, 64, const Radius.circular(15)), violet);

    final ring = Path()
      ..fillType = PathFillType.evenOdd
      ..addOval(Rect.fromCircle(center: const Offset(32, 32), radius: 21.5))
      ..addOval(Rect.fromCircle(center: const Offset(32, 32), radius: 10.5));
    canvas.drawPath(ring, Paint()..color = Colors.white);

    for (final x in [7.0, 45.0]) {
      canvas.drawRRect(RRect.fromLTRBR(x, 28.5, x + 12, 35.5, const Radius.circular(3.5)), violet);
    }

    canvas.drawCircle(const Offset(32, 32), 5.5, Paint()..color = Brand.coral);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// The mark with the word "tickora".
class TickoraLogo extends StatelessWidget {
  const TickoraLogo({super.key, this.size = 36});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        TickoraMark(size: size),
        SizedBox(width: size * 0.28),
        Text(
          'tickora',
          style: TextStyle(
            fontSize: size * 0.72,
            fontWeight: FontWeight.w900,
            letterSpacing: -0.5,
            color: Theme.of(context).colorScheme.onSurface,
          ),
        ),
      ],
    );
  }
}

/// A network image that degrades to a branded placeholder.
class CoverImage extends StatelessWidget {
  const CoverImage({super.key, required this.url, this.aspectRatio = 16 / 9});

  final String? url;
  final double aspectRatio;

  @override
  Widget build(BuildContext context) {
    final placeholder = Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Brand.violet, Brand.violetDark],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      alignment: Alignment.center,
      child: const Opacity(opacity: 0.35, child: TickoraMark(size: 56)),
    );

    final image = url;
    return AspectRatio(
      aspectRatio: aspectRatio,
      child: image == null
          ? placeholder
          : Image.network(
              image,
              fit: BoxFit.cover,
              errorBuilder: (context, error, stack) => placeholder,
              loadingBuilder: (context, child, progress) => progress == null ? child : placeholder,
            ),
    );
  }
}

/// A full-area message with an optional action, for empty and error states.
class MessageView extends StatelessWidget {
  const MessageView({
    super.key,
    required this.icon,
    required this.title,
    this.message,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String? message;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final body = message;
    final label = actionLabel;
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(title, textAlign: TextAlign.center, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            if (body != null) ...[
              const SizedBox(height: 8),
              Text(body, textAlign: TextAlign.center, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            ],
            if (label != null && onAction != null) ...[
              const SizedBox(height: 20),
              FilledButton.tonal(onPressed: onAction, child: Text(label)),
            ],
          ],
        ),
      ),
    );
  }
}

/// A row in a price breakdown.
class AmountRow extends StatelessWidget {
  const AmountRow({super.key, required this.label, required this.value, this.strong = false});

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final style = strong
        ? Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900)
        : Theme.of(context).textTheme.bodyMedium;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: style)),
          Text(value, style: style),
        ],
      ),
    );
  }
}

void showSnack(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message), behavior: SnackBarBehavior.floating));
}
