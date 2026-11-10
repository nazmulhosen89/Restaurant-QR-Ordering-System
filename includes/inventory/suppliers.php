<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Supplier management for the inventory module.
 * Suppliers are never deleted. To stop using one, set its status to inactive,
 * so past purchases keep a valid supplier record.
 *
 * Supplier types:
 *  - registered   : a regular supplier with a business record
 *  - local_market : a local bazar/shop bought from without a formal record
 *
 * Functions:
 *  qrrs_supplier_handle_actions()  - save (runs before any output)
 *  qrrs_supplier_render()          - form + list (runs inside the dashboard)
 */

function qrrs_supplier_types() {
    return [
        'registered'   => 'Registered Supplier',
        'local_market' => 'Local Market',
    ];
}

function qrrs_supplier_redirect( $status ) {
    wp_safe_redirect( add_query_arg( [
        'tab'         => 'inventory',
        'inv_section' => 'suppliers',
        'status'      => $status,
    ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
}

function qrrs_supplier_handle_actions( $restaurant_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'qrrs_suppliers';

    if ( ! qrrs_inventory_can_manage() ) {
        return;
    }

    if ( isset( $_POST['qrrs_supplier_action'] ) && 'save' === $_POST['qrrs_supplier_action'] ) {
        if ( ! isset( $_POST['qrrs_supplier_nonce'] ) || ! wp_verify_nonce( $_POST['qrrs_supplier_nonce'], 'qrrs_supplier_save' ) ) {
            wp_die( 'Security check failed' );
        }

        $name = sanitize_text_field( wp_unslash( $_POST['supplier_name'] ?? '' ) );
        if ( '' === $name ) {
            qrrs_supplier_redirect( 'supplier_name_required' );
        }

        $types = qrrs_supplier_types();
        $type  = sanitize_key( $_POST['supplier_type'] ?? 'registered' );
        if ( ! isset( $types[ $type ] ) ) {
            $type = 'registered';
        }

        $status = ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'active';

        $data = [
            'restaurant_id' => intval( $restaurant_id ),
            'supplier_name' => $name,
            'supplier_type' => $type,
            'phone'         => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'email'         => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'address'       => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
            'status'        => $status,
        ];
        $format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ];

        $edit_id = intval( $_POST['supplier_id'] ?? 0 );
        if ( $edit_id > 0 ) {
            $ok = $wpdb->update( $table, $data, [ 'id' => $edit_id, 'restaurant_id' => $restaurant_id ], $format, [ '%d', '%d' ] );
            qrrs_supplier_redirect( false !== $ok ? 'supplier_updated' : 'error' );
        }

        $data['created_by'] = get_current_user_id();
        $format[]           = '%d';
        $ok = $wpdb->insert( $table, $data, $format );
        qrrs_supplier_redirect( $ok ? 'supplier_saved' : 'error' );
    }
}

function qrrs_supplier_render( $restaurant_id, $can_manage ) {
    global $wpdb;
    $table = $wpdb->prefix . 'qrrs_suppliers';
    $types = qrrs_supplier_types();

    $messages = [
        'supplier_saved'         => [ 'ok', 'Supplier added.' ],
        'supplier_updated'       => [ 'ok', 'Supplier updated.' ],
        'supplier_name_required' => [ 'err', 'Supplier name is required.' ],
        'error'                  => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    if ( isset( $messages[ $status ] ) ) {
        [ $kind, $text ] = $messages[ $status ];
        $bg = 'ok' === $kind ? '#dcfce7' : '#fee2e2';
        $fg = 'ok' === $kind ? '#166534' : '#991b1b';
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:10px 14px; border-radius:6px; margin:12px 0;">' . esc_html( $text ) . '</div>';
    }

    $edit_id  = isset( $_GET['supplier_id'] ) ? intval( $_GET['supplier_id'] ) : 0;
    $edit_row = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND restaurant_id = %d", $edit_id, $restaurant_id ) ) : null;
    $base_url = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'suppliers' ], home_url( '/restaurant-dashboard/' ) );

    if ( $can_manage ) : ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3><?php echo $edit_row ? 'Edit Supplier' : 'Add Supplier'; ?></h3>
        <form method="post" action="<?php echo esc_url( $base_url ); ?>">
            <?php wp_nonce_field( 'qrrs_supplier_save', 'qrrs_supplier_nonce' ); ?>
            <input type="hidden" name="qrrs_supplier_action" value="save">
            <input type="hidden" name="supplier_id" value="<?php echo $edit_row ? intval( $edit_row->id ) : 0; ?>">

            <div class="qrrs-form-grid">
                <div class="qrrs-input-group">
                    <label>Supplier / Shop Name *</label>
                    <input type="text" name="supplier_name" required value="<?php echo esc_attr( $edit_row->supplier_name ?? '' ); ?>" placeholder="e.g. Fresh Meat House">
                </div>
                <div class="qrrs-input-group">
                    <label>Type</label>
                    <select name="supplier_type">
                        <?php foreach ( $types as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $edit_row->supplier_type ?? 'registered', $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-input-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo esc_attr( $edit_row->phone ?? '' ); ?>">
                </div>
                <div class="qrrs-input-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo esc_attr( $edit_row->email ?? '' ); ?>">
                </div>
                <div class="qrrs-input-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active"   <?php selected( $edit_row->status ?? 'active', 'active' ); ?>>Active</option>
                        <option value="inactive" <?php selected( $edit_row->status ?? '', 'inactive' ); ?>>Inactive (stop using)</option>
                    </select>
                </div>
            </div>
            <div class="qrrs-input-group">
                <label>Address</label>
                <textarea name="address" rows="2"><?php echo esc_textarea( $edit_row->address ?? '' ); ?></textarea>
            </div>
            <div style="margin-top:15px;">
                <button type="submit" class="qrrs-btn-save"><?php echo $edit_row ? 'Update Supplier' : 'Add Supplier'; ?></button>
                <?php if ( $edit_row ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" style="margin-left:10px; color:#666;">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE restaurant_id = %d ORDER BY supplier_name ASC",
        $restaurant_id
    ) );
    ?>
    <div class="qrrs-card" style="padding:20px;">
        <h3>Suppliers</h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f1f5f9; text-align:left;">
                    <th>Name</th>
                    <th>Type</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <?php if ( $can_manage ) : ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ( $rows ) : foreach ( $rows as $r ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td style="font-weight:600;"><?php echo esc_html( $r->supplier_name ); ?></td>
                    <td><?php echo esc_html( $types[ $r->supplier_type ] ?? 'Registered Supplier' ); ?></td>
                    <td><?php echo esc_html( $r->phone ?: '—' ); ?></td>
                    <td><?php echo esc_html( $r->email ?: '—' ); ?></td>
                    <td>
                        <?php if ( 'active' === $r->status ) : ?>
                            <span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:10px; font-size:12px;">Active</span>
                        <?php else : ?>
                            <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-size:12px;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <?php if ( $can_manage ) : ?>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( 'supplier_id', intval( $r->id ), $base_url ) ); ?>">Edit</a>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="6" style="text-align:center; padding:25px; color:#94a3b8;">No suppliers added yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}