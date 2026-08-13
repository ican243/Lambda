"""add metadata fields to trade signal logs

Revision ID: 20260813_signal_log_metadata
Revises: afc1394c50b6
"""

from alembic import op
import sqlalchemy as sa


revision = "20260813_signal_log_metadata"
down_revision = "afc1394c50b6"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("py_trade_signal_log", sa.Column("signal", sa.Integer(), nullable=True))
    op.add_column("py_trade_signal_log", sa.Column("event_type", sa.String(length=30), nullable=True))
    op.add_column(
        "py_trade_signal_log",
        sa.Column("source", sa.String(length=20), nullable=False, server_default="scheduler"),
    )
    op.add_column("py_trade_signal_log", sa.Column("evaluated_at", sa.DateTime(), nullable=True))
    op.create_index(
        "ix_py_trade_signal_log_event_type",
        "py_trade_signal_log",
        ["event_type"],
        unique=False,
    )
    op.create_index(
        "ix_py_trade_signal_log_source",
        "py_trade_signal_log",
        ["source"],
        unique=False,
    )
    op.create_index(
        "ix_py_trade_signal_log_ticker_strategy_created",
        "py_trade_signal_log",
        ["ticker", "strategy_name", "created_at"],
        unique=False,
    )


def downgrade() -> None:
    op.drop_index("ix_py_trade_signal_log_ticker_strategy_created", table_name="py_trade_signal_log")
    op.drop_index("ix_py_trade_signal_log_source", table_name="py_trade_signal_log")
    op.drop_index("ix_py_trade_signal_log_event_type", table_name="py_trade_signal_log")
    op.drop_column("py_trade_signal_log", "evaluated_at")
    op.drop_column("py_trade_signal_log", "source")
    op.drop_column("py_trade_signal_log", "event_type")
    op.drop_column("py_trade_signal_log", "signal")
