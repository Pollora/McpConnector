<?php

declare(strict_types=1);

namespace Pollora\McpConnector\Admin;

defined('ABSPATH') || exit;

/**
 * Form controls for the settings screen.
 *
 * Every control is a real form element with a real label. The switch is a
 * checkbox the stylesheet draws differently, not a div listening for clicks: it
 * submits without JavaScript, it reaches the tab order for free, and a screen
 * reader announces it as the checkbox it is.
 *
 * Several controls accept a `$form` identifier. The screen carries two
 * independent submissions — the configuration and the manual creation of a
 * client — and one of them has to sit inside a panel that is itself inside the
 * other's form. Nested forms are not valid HTML and browsers silently discard
 * the inner one, so the second form is an empty element elsewhere in the
 * document and its controls point at it by id.
 */
final class Field
{
    /**
     * An on/off switch with its label and supporting text.
     *
     * @param string $name    Field name, submitted as `1` when on and absent when off.
     * @param string $label   Short statement of what turning it on does.
     * @param string $help    The consequence, or the reason to reach for it.
     * @param bool   $checked Whether it is currently on.
     * @param string $form    Identifier of the form to submit with, when not the enclosing one.
     */
    public static function toggle(string $name, string $label, string $help, bool $checked, string $form = ''): void
    {
        $id = 'mcpc-' . $name;

        printf(
            '<div class="mcpc-toggle">'
            . '<input type="checkbox" class="mcpc-toggle__input" id="%1$s" name="%2$s" value="1"%3$s%4$s>'
            . '<label class="mcpc-toggle__label" for="%1$s">'
            . '<span class="mcpc-toggle__track" aria-hidden="true"><span class="mcpc-toggle__knob"></span></span>'
            . '<span class="mcpc-toggle__text"><span class="mcpc-toggle__title">%5$s</span>'
            . '<span class="mcpc-toggle__help">%6$s</span></span>'
            . '</label></div>',
            esc_attr($id),
            esc_attr($name),
            checked($checked, true, false),
            self::formAttribute($form), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns either '' or a form="" attribute built with esc_attr(); the sniff does not follow method calls.
            esc_html($label),
            esc_html($help),
        );
    }

    /**
     * One switch among several submitted under the same name.
     *
     * Distinct from {@see self::toggle()} because the two submit differently: a
     * toggle is one boolean, present or absent, while these accumulate into an
     * array and each therefore carries its own value. Sharing one method would
     * mean a value argument that is meaningless for two thirds of the callers.
     *
     * @param string $name    Field name; submitted as an array.
     * @param string $value   The value this switch contributes when on.
     * @param string $label   Short statement of what turning it on does.
     * @param string $help    The consequence, or the reason to reach for it.
     * @param bool   $checked Whether it is currently on.
     */
    public static function choice(string $name, string $value, string $label, string $help, bool $checked): void
    {
        $id = 'mcpc-' . $name . '-' . $value;

        printf(
            '<div class="mcpc-toggle">'
            . '<input type="checkbox" class="mcpc-toggle__input" id="%1$s" name="%2$s[]" value="%3$s"%4$s>'
            . '<label class="mcpc-toggle__label" for="%1$s">'
            . '<span class="mcpc-toggle__track" aria-hidden="true"><span class="mcpc-toggle__knob"></span></span>'
            . '<span class="mcpc-toggle__text"><span class="mcpc-toggle__title">%5$s</span>'
            . '<span class="mcpc-toggle__help">%6$s</span></span>'
            . '</label></div>',
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            checked($checked, true, false),
            esc_html($label),
            esc_html($help),
        );
    }

    /**
     * A single-line input.
     *
     * @param string $name  Field name.
     * @param string $label Label text.
     * @param string $help  Supporting text below the control.
     * @param string $value Current value.
     * @param bool   $mono  Whether the value is machine-readable and should be set in the mono face.
     * @param string $form  Identifier of the form to submit with, when not the enclosing one.
     */
    public static function input(
        string $name,
        string $label,
        string $help,
        string $value,
        bool $mono = false,
        string $form = '',
    ): void {
        $id = 'mcpc-' . $name;

        printf(
            '<div class="mcpc-field">'
            . '<label class="mcpc-field__label" for="%1$s">%2$s</label>'
            . '<input type="text" class="mcpc-field__control%3$s" id="%1$s" name="%4$s" value="%5$s" '
            . 'aria-describedby="%1$s-help" spellcheck="false"%6$s>'
            . '<p class="mcpc-field__help" id="%1$s-help">%7$s</p>'
            . '</div>',
            esc_attr($id),
            esc_html($label),
            $mono ? ' mcpc-field__control--mono' : '',
            esc_attr($name),
            esc_attr($value),
            self::formAttribute($form), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns either '' or a form="" attribute built with esc_attr(); the sniff does not follow method calls.
            esc_html($help),
        );
    }

    /**
     * A multi-line input.
     *
     * @param string $name        Field name.
     * @param string $label       Label text.
     * @param string $help        Supporting text below the control.
     * @param string $value       Current value.
     * @param int    $rows        Visible rows.
     * @param string $placeholder Placeholder shown when empty.
     * @param string $form        Identifier of the form to submit with, when not the enclosing one.
     */
    public static function textarea(
        string $name,
        string $label,
        string $help,
        string $value,
        int $rows = 4,
        string $placeholder = '',
        string $form = '',
    ): void {
        $id = 'mcpc-' . $name;

        printf(
            '<div class="mcpc-field">'
            . '<label class="mcpc-field__label" for="%1$s">%2$s</label>'
            . '<textarea class="mcpc-field__control mcpc-field__control--area" id="%1$s" name="%3$s" rows="%4$s" '
            . 'placeholder="%5$s" aria-describedby="%1$s-help" spellcheck="false"%6$s>%7$s</textarea>'
            . '<p class="mcpc-field__help" id="%1$s-help">%8$s</p>'
            . '</div>',
            esc_attr($id),
            esc_html($label),
            esc_attr($name),
            esc_attr((string) $rows),
            esc_attr($placeholder),
            self::formAttribute($form), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns either '' or a form="" attribute built with esc_attr(); the sniff does not follow method calls.
            esc_textarea($value),
            esc_html($help),
        );
    }

    /**
     * The address a client has to be given, laid out so it cannot be missed.
     *
     * A readonly input rather than a `<code>` block: it can be focused, it
     * selects on focus, and Ctrl+C then works without a mouse — which a styled
     * span cannot offer.
     *
     * @param string $id    Element identifier, referenced by the copy button.
     * @param string $label What this address is.
     * @param string $value The address.
     * @param string $help  What the client does with it.
     */
    public static function address(string $id, string $label, string $value, string $help): void
    {
        printf(
            '<div class="mcpc-address">'
            . '<label class="mcpc-address__label" for="%1$s">%2$s</label>'
            . '<div class="mcpc-address__row">'
            . '<input type="text" class="mcpc-address__value" id="%1$s" value="%3$s" readonly '
            . 'spellcheck="false" aria-describedby="%1$s-help">'
            . '<button type="button" class="mcpc-button mcpc-button--primary mcpc-copy" data-copy-from="%1$s">%4$s</button>'
            . '</div>'
            . '<p class="mcpc-address__help" id="%1$s-help">%5$s</p>'
            . '</div>',
            esc_attr($id),
            esc_html($label),
            esc_attr($value),
            esc_html__('Copy', 'amphibee-mcp-connector'),
            esc_html($help),
        );
    }

    /**
     * One selectable post type, drawn as a card rather than a bare checkbox.
     *
     * @param string $name    Field name, submitted as an array.
     * @param string $value   Post type slug.
     * @param string $label   Human-readable label.
     * @param string $note    A short qualifier shown at the foot of the card.
     * @param bool   $warn    Whether the qualifier is a caution rather than a fact.
     * @param bool   $checked Whether it is currently selected.
     */
    public static function typeCard(
        string $name,
        string $value,
        string $label,
        string $note,
        bool $warn,
        bool $checked,
    ): void {
        $id = 'mcpc-' . $name . '-' . $value;

        printf(
            '<div class="mcpc-card-check">'
            . '<input type="checkbox" class="mcpc-card-check__input" id="%1$s" name="%2$s[]" value="%3$s"%4$s>'
            . '<label class="mcpc-card-check__label" for="%1$s">'
            . '<span class="mcpc-card-check__name">%5$s</span>'
            . '<code class="mcpc-card-check__slug">%3$s</code>'
            . '<span class="mcpc-card-check__note%6$s">%7$s</span>'
            . '</label></div>',
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            checked($checked, true, false),
            esc_html($label),
            $warn ? ' mcpc-card-check__note--warn' : '',
            esc_html($note),
        );
    }

    /**
     * One third-party ability, with everything its provider says about it.
     *
     * The identifier is hashed because an ability name carries a slash and a
     * `for` attribute has to match an id exactly; the hash is stable within a
     * page load, which is all a label needs.
     *
     * @param string $name        Field name, submitted as an array.
     * @param string $value       Fully-qualified ability name.
     * @param string $label       The provider's own label.
     * @param string $description The provider's own description.
     * @param bool   $recommended Whether a shipped profile recommends it.
     * @param bool   $checked     Whether it is currently selected.
     */
    public static function ability(
        string $name,
        string $value,
        string $label,
        string $description,
        bool $recommended,
        bool $checked,
    ): void {
        $id = 'mcpc-ability-' . md5($value);

        printf(
            '<div class="mcpc-ability">'
            . '<input type="checkbox" class="mcpc-ability__input" id="%1$s" name="%2$s[]" value="%3$s"%4$s>'
            . '<label class="mcpc-ability__label" for="%1$s">'
            . '<span class="mcpc-ability__box" aria-hidden="true"></span>'
            . '<span class="mcpc-ability__name">%5$s%6$s</span>'
            . '<code class="mcpc-ability__slug">%3$s</code>'
            . '<span class="mcpc-ability__desc">%7$s</span>'
            . '</label></div>',
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            checked($checked, true, false),
            esc_html($label !== '' ? $label : $value),
            $recommended
                ? '<span class="mcpc-badge mcpc-badge--recommended">' . esc_html__('recommended', 'amphibee-mcp-connector') . '</span>'
                : '',
            esc_html($description),
        );
    }

    /**
     * A hidden value that belongs to a form declared elsewhere in the document.
     *
     * @param string $name  Field name.
     * @param string $value Field value.
     * @param string $form  Identifier of the form to submit with.
     */
    public static function hidden(string $name, string $value, string $form = ''): void
    {
        printf(
            '<input type="hidden" name="%s" value="%s"%s>',
            esc_attr($name),
            esc_attr($value),
            self::formAttribute($form), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns either '' or a form="" attribute built with esc_attr(); the sniff does not follow method calls.
        );
    }

    /**
     * The `form` attribute, or nothing when the control sits in its own form.
     *
     * @param string $form Identifier of the form to submit with.
     *
     * @return string The attribute, ready to concatenate, already escaped.
     */
    private static function formAttribute(string $form): string
    {
        return $form === '' ? '' : ' form="' . esc_attr($form) . '"';
    }
}
