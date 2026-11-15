<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Supplier management for the inventory module.
 * Suppliers are never deleted. To stop using one, set its status to inactive,
 * so past purchases keep a valid supplier record[cite: 9].
 *
 * Supplier types:
 *  - registered   : a regular supplier with a business record[cite: 9]
 *  - local_market : a local bazar/shop bought from without a formal record[cite: 9]
 *
 * Functions:
 *  qrrs_supplier_handle_actions()  - save (runs before any output)[cite: 9]
 *  qrrs_supplier_render()          - form + list (runs inside the dashboard)[cite: 9]
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
        echo '<div style="background:' . $bg . '; color:' . $fg . '; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; font-size:14px;">' . esc_html( $text ) . '</div>';
    }

    $edit_id  = isset( $_GET['supplier_id'] ) ? intval( $_GET['supplier_id'] ) : 0;
    $edit_row = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND restaurant_id = %d", $edit_id, $restaurant_id ) ) : null;
    $base_url = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'suppliers' ], home_url( '/restaurant-dashboard/' ) );

    if ( $can_manage ) : ?>
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px !important; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:20px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <span class="material-icons-outlined" style="color:#2563eb; font-size:22px;"><?php echo $edit_row ? 'edit_note' : 'local_shipping'; ?></span>
            <h3 style="margin:0; font-size:17px; color:#1e293b; font-weight:700;"><?php echo $edit_row ? 'Edit Supplier' : 'Add Supplier'; ?></h3>
        </div>

        <form method="post" action="<?php echo esc_url( $base_url ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'qrrs_supplier_save', 'qrrs_supplier_nonce' ); ?>
            <input type="hidden" name="qrrs_supplier_action" value="save">
            <input type="hidden" name="supplier_id" value="<?php echo $edit_row ? intval( $edit_row->id ) : 0; ?>">

            <div class="qrrs-inv-form-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:18px;">
                <div class="qrrs-inv-field">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Supplier / Shop Name *</label>
                    <input type="text" name="supplier_name" required value="<?php echo esc_attr( $edit_row->supplier_name ?? '' ); ?>" placeholder="e.g. Fresh Meat House" style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                </div>
                <div class="qrrs-inv-field">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Type</label>
                    <select name="supplier_type" style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                        <?php foreach ( $types as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $edit_row->supplier_type ?? 'registered', $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="qrrs-inv-field">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Phone</label>
                    <input type="text" name="phone" value="<?php echo esc_attr( $edit_row->phone ?? '' ); ?>" placeholder="01XXXXXXXXX" style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                </div>
                <div class="qrrs-inv-field">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Email</label>
                    <input type="email" name="email" value="<?php echo esc_attr( $edit_row->email ?? '' ); ?>" placeholder="supplier@gmail.com" style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                </div>
                <div class="qrrs-inv-field">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Status</label>
                    <select name="status" style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                        <option value="active"   <?php selected( $edit_row->status ?? 'active', 'active' ); ?>>Active</option>
                        <option value="inactive" <?php selected( $edit_row->status ?? '', 'inactive' ); ?>>Inactive (stop using)</option>
                    </select>
                </div>
            </div>
            
            <!-- Address field & Action buttons side by side -->
            <div style="display:flex; gap:20px; align-items:flex-end; flex-wrap:wrap; margin-top:5px;">
                <div class="qrrs-inv-field" style="flex: 1; min-width: 280px;">
                    <label style="display:block; font-weight:600; font-size:13px; margin-bottom:6px; color:#475569;">Address</label>
                    <input type="text" name="address" value="<?php echo esc_attr( $edit_row->address ?? '' ); ?>" placeholder="Full store address..." style="width:100%; height:42px; padding:0 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; box-sizing:border-box;">
                </div>
                
                <div style="display:flex; align-items:center; gap:12px; height:42px;">
                    <button type="submit" class="qrrs-btn-save" style="height:42px; padding:0 24px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">
                        <?php echo $edit_row ? 'Update Supplier' : 'Add Supplier'; ?>
                    </button>
                    <?php if ( $edit_row ) : ?>
                        <a href="<?php echo esc_url( $base_url ); ?>" style="text-decoration:none; color:#64748b; font-weight:600; font-size:14px; white-space:nowrap;">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <?php endif;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE restaurant_id = %d ORDER BY supplier_name ASC",
        $restaurant_id
    ) );
    ?>
    <div class="qrrs-card" style="background:#fff; padding:25px; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <h3 style="margin-top:0; font-size:18px; color:#1e293b; margin-bottom:15px;">Suppliers List</h3>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:left;">
                    <th style="padding:12px; font-size:13px; color:#475569;">Name</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Type</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Phone</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Email</th>
                    <th style="padding:12px; font-size:13px; color:#475569;">Status</th>
                    <?php if ( $can_manage ) : ?><th style="padding:12px; font-size:13px; color:#475569; text-align:right;">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ( $rows ) : foreach ( $rows as $r ) : ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:600; color:#1e293b;"><?php echo esc_html( $r->supplier_name ); ?></td>
                    <td style="padding:12px; color:#475569;"><?php echo esc_html( $types[ $r->supplier_type ] ?? 'Registered Supplier' ); ?></td>
                    <td style="padding:12px; color:#64748b;"><?php echo esc_html( $r->phone ?: '—' ); ?></td>
                    <td style="padding:12px; color:#64748b;"><?php echo esc_html( $r->email ?: '—' ); ?></td>
                    <td style="padding:12px;">
                        <?php if ( 'active' === $r->status ) : ?>
                            <span style="background:#dcfce7; color:#166534; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase;">Active</span>
                        <?php else : ?>
                            <span style="background:#fee2e2; color:#dc2626; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <?php if ( $can_manage ) : ?>
                    <td style="padding:12px; text-align:right;">
                        <a href="<?php echo esc_url( add_query_arg( 'supplier_id', intval( $r->id ), $base_url ) ); ?>" style="display:inline-block; padding:6px 14px; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; border-radius:6px; font-size:12px; font-weight:600; text-decoration:none;">Edit</a>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">No suppliers added yet[cite: 9].</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}